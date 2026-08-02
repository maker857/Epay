<?php
namespace lib;

use Exception;

class Transfer
{

    static public $payee_err_code = [ //收款方原因导致的失败编码
		'PAYEE_NOT_EXIST','PAYEE_ACCOUNT_STATUS_ERROR','CARD_BIN_ERROR','PAYEE_CARD_INFO_ERROR','PERM_AML_NOT_REALNAME_REV','PAYEE_USER_INFO_ERROR','PAYEE_ACC_OCUPIED','PERMIT_NON_BANK_LIMIT_PAYEE','PAYEE_TRUSTEESHIP_ACC_OVER_LIMIT','PAYEE_ACCOUNT_NOT_EXSIT','PAYEE_USERINFO_STATUS_ERROR','TRUSTEESHIP_RECIEVE_QUOTA_LIMIT','EXCEED_LIMIT_UNRN_DM_AMOUNT','INVALID_CARDNO','RELEASE_USER_FORBBIDEN_RECIEVE','PAYEE_USER_TYPE_ERROR','PAYEE_NOT_RELNAME_CERTIFY','PERMIT_LIMIT_PAYEE',

		'OPENID_ERROR','NAME_MISMATCH','V2_ACCOUNT_SIMPLE_BAN','MONEY_LIMIT','EXCEED_PAYEE_ACCOUNT_LIMIT','PAYEE_ACCOUNT_ABNORMAL','APPID_OR_OPENID_ERR',

		'REALNAME_CHECK_ERROR','RE_USER_NAME_CHECK_ERROR','ERR_TJ_BLACK','USER_FROZEN','TRANSFER_FAIL','TRANSFER_FEE_LIMIT_ERROR',

		'ACCOUNT_FROZEN','REAL_NAME_CHECK_FAIL','NAME_NOT_CORRECT','OPENID_INVALID','TRANSFER_QUOTA_EXCEED','DAY_RECEIVED_QUOTA_EXCEED','MONTH_RECEIVED_QUOTA_EXCEED','DAY_RECEIVED_COUNT_EXCEED','ID_CARD_NOT_CORRECT','ACCOUNT_NOT_EXIST','TRANSFER_RISK','REALNAME_ACCOUNT_RECEIVED_QUOTA_EXCEED','RECEIVE_ACCOUNT_NOT_PERMMIT','PAYEE_ACCOUNT_ABNORMAL','BLOCK_B2C_USERLIMITAMOUNT_BSRULE_MONTH','BLOCK_B2C_USERLIMITAMOUNT_MONTH',
	];

    //通用转账
    //type alipay:支付宝,wxpay:微信,qqpay:QQ钱包,bank:银行卡
    public static function submit($type, $channel, $out_biz_no, $payee_account, $payee_real_name, $money, $title = null, $desc = null){
        global $conf;

        $bizParam = [
            'type' => $type,
            'out_biz_no' => $out_biz_no,
            'payee_account' => $payee_account,
            'payee_real_name' => $payee_real_name,
            'money' => $money,
            'transfer_name' => $title?$title:$conf['transfer_name'],
            'transfer_desc' => $desc?$desc:$conf['transfer_desc'],
        ];
        return \lib\Plugin::call('transfer', $channel, $bizParam);
    }

    private static function rollbackToDepth($depth){
        global $DB;
        while($DB->getTransactionDepth() > $depth){
            if(!$DB->rollBack()) break;
        }
    }

    private static function completeReservedTransfer($biz_no, $result){
        global $DB;
        $initialDepth = $DB->getTransactionDepth();
        if(!$DB->beginTransaction()) throw new \RuntimeException('Unable to start transfer completion transaction: '.$DB->error());
        try{
            $order = $DB->getRow('SELECT * FROM pre_transfer WHERE biz_no=:biz_no FOR UPDATE', [':biz_no'=>$biz_no]);
            if(!$order) throw new \RuntimeException('Reserved transfer not found: '.$biz_no);
            if((int)$order['status'] === 2) throw new \RuntimeException('Cannot complete a failed transfer: '.$biz_no);
            if((int)$order['status'] !== 1){
                $status = isset($result['status']) ? (int)$result['status'] : 0;
                $data = [
                    'status'=>$status,
                    'pay_order_no'=>$result['orderid'] ?? $order['pay_order_no'],
                    'result'=>'',
                ];
                if(isset($result['account'])) $data['account'] = $result['account'];
                if($status === 1) $data['paytime'] = $result['paydate'] ?? 'NOW()';
                if(isset($result['wxpackage'])) $data['ext'] = $result['wxpackage'];
                if($DB->update('transfer', $data, ['biz_no'=>$biz_no]) === false){
                    throw new \RuntimeException('Unable to finalize transfer '.$biz_no.': '.$DB->error());
                }
            }
            if(!$DB->commit()) throw new \RuntimeException('Unable to commit transfer completion '.$biz_no.': '.$DB->error());
            return $DB->find('transfer', '*', ['biz_no'=>$biz_no]);
        }catch(\Throwable $e){
            self::rollbackToDepth($initialDepth);
            throw $e;
        }
    }

    private static function failReservedTransfer($biz_no, $message){
        global $DB;
        $initialDepth = $DB->getTransactionDepth();
        if(!$DB->beginTransaction()) throw new \RuntimeException('Unable to start transfer failure transaction: '.$DB->error());
        try{
            $order = $DB->getRow('SELECT * FROM pre_transfer WHERE biz_no=:biz_no FOR UPDATE', [':biz_no'=>$biz_no]);
            if(!$order) throw new \RuntimeException('Reserved transfer not found: '.$biz_no);
            if((int)$order['status'] === 1) throw new \RuntimeException('Cannot fail a successful transfer: '.$biz_no);
            if((int)$order['status'] !== 2){
                $updated = $DB->exec('UPDATE pre_transfer SET status=2,result=:result WHERE biz_no=:biz_no AND status IN (0,3,4)', [':result'=>mb_substr((string)$message, 0, 80), ':biz_no'=>$biz_no]);
                if($updated !== 1) throw new \RuntimeException('Unable to mark transfer failed '.$biz_no.': '.($DB->error() ?: 'unexpected affected row count'));
                if((int)$order['uid'] > 0 && (float)$order['costmoney'] > 0){
                    changeUserMoney($order['uid'], $order['costmoney'], true, '代付退回', $biz_no);
                }
            }
            if(!$DB->commit()) throw new \RuntimeException('Unable to commit transfer failure '.$biz_no.': '.$DB->error());
            return $DB->find('transfer', '*', ['biz_no'=>$biz_no]);
        }catch(\Throwable $e){
            self::rollbackToDepth($initialDepth);
            throw $e;
        }
    }

    private static function notePendingTransfer($biz_no, $message){
        global $DB;
        $result = mb_substr((string)$message, 0, 80);
        if($DB->update('transfer', ['result'=>$result], ['biz_no'=>$biz_no, 'status'=>0]) === false){
            error_log('[transfer reconciliation] unable to update '.$biz_no.': '.$DB->error());
        }
    }

    private static function isExplicitProviderFailure($result){
        return is_array($result) && isset($result['status']) && (int)$result['status'] === 2;
    }

    public static function adminSetStatus($biz_no, $status, $reason = ''){
        try{
            if((int)$status === 1){
                self::completeReservedTransfer($biz_no, [
                    'status'=>1,
                    'paydate'=>'NOW()',
                    'orderid'=>null,
                ]);
                return ['code'=>0, 'msg'=>'succ'];
            }
            if((int)$status === 2){
                self::failReservedTransfer($biz_no, $reason ?: '管理员确认转账失败');
                return ['code'=>0, 'msg'=>'succ'];
            }
            return ['code'=>-1, 'msg'=>'管理员只能确认转账成功或失败'];
        }catch(\Throwable $e){
            return ['code'=>-1, 'msg'=>$e->getMessage()];
        }
    }

    public static function deleteFinalized($biz_no){
        global $DB;
        $initialDepth = $DB->getTransactionDepth();
        if(!$DB->beginTransaction()) return ['code'=>-1, 'msg'=>'无法开始删除事务: '.$DB->error()];
        try{
            $order = $DB->getRow('SELECT status FROM pre_transfer WHERE biz_no=:biz_no FOR UPDATE', [':biz_no'=>$biz_no]);
            if(!$order) throw new \RuntimeException('付款记录不存在');
            if(!in_array((int)$order['status'], [1, 2], true)){
                throw new \RuntimeException('待处理或已预留余额的转账不能删除');
            }
            $deleted = $DB->delete('transfer', ['biz_no'=>$biz_no]);
            if($deleted !== 1) throw new \RuntimeException('删除转账失败: '.($DB->error() ?: 'unexpected affected row count'));
            if(!$DB->commit()) throw new \RuntimeException('提交删除事务失败: '.$DB->error());
            return ['code'=>0, 'msg'=>'succ'];
        }catch(\Throwable $e){
            self::rollbackToDepth($initialDepth);
            return ['code'=>-1, 'msg'=>$e->getMessage()];
        }
    }

    public static function add($uid, $type, $out_biz_no, $payee_account, $payee_real_name, $money, $title = null, $desc = null, $bookid = null, $channelid = null, $submitter = null){
        global $conf, $DB, $userrow, $siteurl;
        $biz_no = $out_biz_no;
        if(strlen($biz_no)!=19 || !is_numeric($biz_no)) $biz_no = date("YmdHis").rand(11111,99999);

        if($uid > 0){
            if($conf['transfer_minmoney']>0 && $money<$conf['transfer_minmoney']) return ['code'=>-1, 'msg'=>'单笔最小代付金额限制为'.$conf['transfer_minmoney'].'元'];
            if($conf['transfer_maxmoney']>0 && $money>$conf['transfer_maxmoney']) return ['code'=>-1, 'msg'=>'单笔最大代付金额限制为'.$conf['transfer_maxmoney'].'元'];
            if($conf['transfer_maxlimit']>0){
                $a_count = $DB->getColumn('SELECT count(*) FROM pre_transfer WHERE uid=:uid AND type=:type AND account=:account AND paytime>=:paytime', [':uid'=>$uid, ':type'=>$type, ':account'=>$payee_account, ':paytime'=>date('Y-m-d').' 00:00:00']);
                if($a_count >= $conf['transfer_maxlimit']){
                    return ['code'=>-1, 'msg'=>'您今天向该账号的转账次数已达到上限'];
                }
            }
        }
        
        if(!$channelid){
            if($type=='alipay'){
                $channelid = $conf['transfer_alipay'];
            }elseif($type=='wxpay'){
                $channelid = $conf['transfer_wxpay'];
            }elseif($type=='qqpay'){
                if (!is_numeric($payee_account) || strlen($payee_account)<6 || strlen($payee_account)>10) return ['code'=>-1, 'msg'=>'QQ号码格式错误'];
                $channelid = $conf['transfer_qqpay'];
            }elseif($type=='bank'){
                $channelid = $conf['transfer_bank'];
            }else{
                return ['code'=>-1, 'msg'=>'type参数错误'];
            }
            if(!$channelid) return ['code'=>-1, 'msg'=>'未开启此转账方式'];
        }
        if($channelid > 0){
            $channel = \lib\Channel::get($channelid, $userrow['channelinfo']);
            if(!$channel) return ['code'=>-1, 'msg'=>'当前支付通道信息不存在'];
        }

        if($uid > 0){
            if(class_exists('\\lib\\AlipaySATF\\AlipaySATF') && $conf['alipay_satf']==1 && ($type=='alipay' || $type=='bank' && $conf['transfer_alipay']==$conf['transfer_bank'])){
                if(!$bookid) $bookid = $DB->findColumn('satf_account_book', 'id', ['uid'=>$uid, 'status'=>1], 'money DESC');
                $satf = new \lib\AlipaySATF\AlipaySATF();
                $params = [
                    'out_biz_no' => $out_biz_no,
                    'account' => $payee_account,
                    'name' => $payee_real_name,
                    'money' => $money,
                    'remark' => $desc,
                ];
                $result = $satf->transfer($bookid, $type=='bank' ? 2 : 1, $params, $uid);
                return $result;
            }
        }

        $trans = $DB->find('transfer', '*', ['out_biz_no' => $out_biz_no, 'uid' => $uid]);
        if($trans) return ['code'=>-1, 'msg'=>'该交易号已存在，请更换交易号', 'status'=>(int)$trans['status'], 'biz_no'=>$trans['biz_no'], 'out_biz_no'=>$out_biz_no];

        $initialDepth = $DB->getTransactionDepth();
        if(!$DB->beginTransaction()) throw new \RuntimeException('Unable to start transfer reservation transaction: '.$DB->error());
        $need_money = null;
        try{
            if($uid > 0){
                $userrow = $DB->getRow('SELECT * FROM pre_user WHERE uid=:uid FOR UPDATE', [':uid'=>$uid]);
                if(!$userrow) throw new \RuntimeException('Merchant not found for transfer UID '.$uid);
                if($userrow['settle']==0){
                    self::rollbackToDepth($initialDepth);
                    return ['code'=>-1, 'msg'=>'您的商户出现异常，无法使用代付功能'];
                }
                $trans = $DB->find('transfer', '*', ['out_biz_no'=>$out_biz_no, 'uid'=>$uid]);
                if($trans){
                    self::rollbackToDepth($initialDepth);
                    return ['code'=>-1, 'msg'=>'该交易号已存在，请更换交易号', 'status'=>(int)$trans['status'], 'biz_no'=>$trans['biz_no'], 'out_biz_no'=>$out_biz_no];
                }
                if($conf['settle_type']==1){
                    $today=date("Y-m-d").' 00:00:00';
                    $order_today=$DB->getColumn("SELECT SUM(realmoney) from pre_order where uid=:uid and tid<>2 and status=1 and endtime>=:today", [':uid'=>$uid, ':today'=>$today]);
                    if($order_today === false && $DB->error()) throw new \RuntimeException('Unable to calculate transferable balance: '.$DB->error());
                    if(!$order_today) $order_today = 0;
                    $enable_money=round($userrow['money']-$order_today,2);
                    if($enable_money<0)$enable_money=0;
                }else{
                    $enable_money=$userrow['money'];
                }
                if(!$conf['transfer_rate'])$conf['transfer_rate'] = $conf['settle_rate'];
                $need_money = round($money + $money*$conf['transfer_rate']/100,2);
                if($need_money>$enable_money){
                    self::rollbackToDepth($initialDepth);
                    return ['code'=>-1, 'msg'=>'需支付金额大于可转账余额'];
                }
            }

            $reservedStatus = $channelid == -1 ? 3 : 0;
            $data = ['biz_no'=>$biz_no, 'out_biz_no'=>$out_biz_no, 'uid'=>$uid, 'type'=>$type, 'channel'=>$channelid, 'account'=>$payee_account, 'username'=>$payee_real_name, 'money'=>$money, 'costmoney'=>$need_money??$money, 'addtime'=>'NOW()', 'status'=>$reservedStatus, 'desc'=>$title?$title:$desc, 'result'=>$channelid == -1 ? '等待管理员审核' : '等待提交到支付平台'];
            if($DB->insert('transfer', $data) === false) throw new \RuntimeException('Unable to reserve transfer '.$biz_no.': '.$DB->error());
            if($need_money>0){
                changeUserMoney2($uid, $userrow['money'], $need_money, false, '代付', $biz_no);
            }
            if(!$DB->commit()) throw new \RuntimeException('Unable to commit transfer reservation '.$biz_no.': '.$DB->error());
        }catch(\Throwable $e){
            self::rollbackToDepth($initialDepth);
            throw $e;
        }

        if($channelid == -1){
            return ['code'=>0, 'status'=>3, 'orderid'=>null, 'biz_no'=>$biz_no, 'out_biz_no'=>$out_biz_no, 'cost_money'=>$need_money, 'msg'=>'提交成功！请等待管理员审核转账。'];
        }

        try{
            $result = is_callable($submitter)
                ? call_user_func($submitter, $type, $channel, $biz_no, $payee_account, $payee_real_name, $money, $title, $desc)
                : self::submit($type, $channel, $biz_no, $payee_account, $payee_real_name, $money, $title, $desc);
        }catch(\Throwable $e){
            self::notePendingTransfer($biz_no, '平台结果待查询: '.$e->getMessage());
            error_log('[transfer reconciliation] '.$biz_no.' provider exception: '.$e->getMessage());
            return ['code'=>-1, 'status'=>0, 'biz_no'=>$biz_no, 'out_biz_no'=>$out_biz_no, 'cost_money'=>$need_money, 'msg'=>'转账结果暂不明确，请通过原交易号查询'];
        }

        $result['biz_no'] = $biz_no;
        $result['out_biz_no'] = $out_biz_no;
        $result['cost_money'] = $need_money;
        if(($result['code'] ?? -1) == 0){
            try{
                self::completeReservedTransfer($biz_no, $result);
            }catch(\Throwable $e){
                self::notePendingTransfer($biz_no, '本地结果待对账');
                error_log('[transfer reconciliation] '.$biz_no.' local completion failed: '.$e->getMessage());
                return ['code'=>-1, 'status'=>0, 'biz_no'=>$biz_no, 'out_biz_no'=>$out_biz_no, 'cost_money'=>$need_money, 'msg'=>'平台已受理，本地状态待对账'];
            }
            if((int)$result['status'] === 1){
                $result['msg']='转账成功！转账单据号:'.($result['orderid'] ?? '').' 支付时间:'.($result['paydate'] ?? '');
            }elseif(isset($result['wxpackage'])){
                $jumpurl = $siteurl.'paypage/wxtrans.php?type=transfer&id='.$biz_no;
                $result['msg']='提交成功！请在微信打开 '.$jumpurl.' 确认收款。';
                $result['jumpurl'] = $jumpurl;
            }else{
                $result['msg']='提交成功！转账处理中。';
            }
            return $result;
        }

        if(!self::isExplicitProviderFailure($result)){
            self::notePendingTransfer($biz_no, '平台结果待查询: '.($result['msg'] ?? '未知响应'));
            return [
                'code'=>-1,
                'status'=>0,
                'biz_no'=>$biz_no,
                'out_biz_no'=>$out_biz_no,
                'cost_money'=>$need_money,
                'msg'=>'转账结果暂不明确，请通过原交易号查询',
            ];
        }

        $failureMessage = $result['errmsg'] ?? ($result['msg'] ?? '支付平台拒绝转账');
        try{
            self::failReservedTransfer($biz_no, $failureMessage);
            $result['status'] = 2;
            return $result;
        }catch(\Throwable $e){
            self::notePendingTransfer($biz_no, '失败结果待对账');
            error_log('[transfer reconciliation] '.$biz_no.' failure finalization failed: '.$e->getMessage());
            return ['code'=>-1, 'status'=>0, 'biz_no'=>$biz_no, 'out_biz_no'=>$out_biz_no, 'cost_money'=>$need_money, 'msg'=>'平台返回失败，本地退款状态待对账'];
        }
    }

    //转账状态刷新
    public static function status($biz_no, $queryHandler = null){
        global $DB;
        $order = $DB->find('transfer', '*', ['biz_no' => $biz_no]);
        if(!$order) return ['code'=>-1, 'msg'=>'付款记录不存在'];
        
        $channelinfo = null;
        if($order['uid'] > 0){
            $channelinfo = $DB->findColumn('user', 'channelinfo', ['uid'=>$order['uid']]);
        }
        $channel = \lib\Channel::get($order['channel'], $channelinfo);
        if(!$channel) return ['code'=>-1, 'msg'=>'支付通道不存在'];

        $result = is_callable($queryHandler)
            ? call_user_func($queryHandler, $order['type'], $channel, $biz_no, $order['pay_order_no'])
            : self::query($order['type'], $channel, $biz_no, $order['pay_order_no']);
        if($result['code'] == 0){
            if($result['status'] == 2){
                if($order['status'] == 0 || $order['status'] == 3){
                    self::failReservedTransfer($biz_no, $result['errmsg'] ?? '转账失败');
                }
                $result['msg'] = '转账失败：'.($result['errmsg']?$result['errmsg']:'原因未知');
            }elseif($result['status'] == 1){
                if($order['status'] == 0 || $order['status'] == 3){
                    self::completeReservedTransfer($biz_no, $result);
                }
                $result['msg'] = '转账成功！';
            }else{
                $result['msg'] = '转账处理中，请稍后查询结果。';
            }
        }
        return $result;
    }

    //转账查询
    //status 0:处理中 1:成功 2:失败
    public static function query($type, $channel, $biz_no, $pay_order_no){
        $bizParam = [
            'type' => $type,
            'out_biz_no' => $biz_no,
            'orderid' => $pay_order_no
        ];
        return \lib\Plugin::call('transfer_query', $channel, $bizParam);
    }

    //撤销转账
    public static function cancel($biz_no){
        global $DB;
        $order = $DB->find('transfer', '*', ['biz_no' => $biz_no]);
        if(!$order) return ['code'=>-1, 'msg'=>'付款记录不存在'];

        $channelinfo = null;
        if($order['uid'] > 0){
            $channelinfo = $DB->findColumn('user', 'channelinfo', ['uid'=>$order['uid']]);
        }
        $channel = \lib\Channel::get($order['channel'], $channelinfo);
        if(!$channel) return ['code'=>-1, 'msg'=>'支付通道不存在'];

        $bizParam = [
            'type' => $order['type'],
            'out_biz_no' => $order['biz_no'],
            'orderid' => $order['pay_order_no'],
        ];
        $result = \lib\Plugin::call('transfer_cancel', $channel, $bizParam);
        if($result['code'] == 0){
            self::failReservedTransfer($biz_no, '转账已撤销');
            $result['msg'] = '转账已撤销';
        }
        return $result;
    }

    //账户余额查询
    public static function balance($type, $channel, $user_id = null){
        $bizParam = [
            'type' => $type,
            'user_id' => $user_id
        ];
        return \lib\Plugin::call('balance_query', $channel, $bizParam);
    }

    //转账凭证查询
    public static function proof($biz_no){
        global $DB;
        $order = $DB->find('transfer', '*', ['biz_no' => $biz_no]);
        if(!$order) return ['code'=>-1, 'msg'=>'付款记录不存在'];
        
        $channelinfo = null;
        if($order['uid'] > 0){
            $channelinfo = $DB->findColumn('user', 'channelinfo', ['uid'=>$order['uid']]);
        }
        $channel = \lib\Channel::get($order['channel'], $channelinfo);
        if(!$channel) return ['code'=>-1, 'msg'=>'支付通道不存在'];

        $bizParam = [
            'type' => $order['type'],
            'out_biz_no' => $biz_no,
            'orderid' => $order['pay_order_no']
        ];
        return \lib\Plugin::call('transfer_proof', $channel, $bizParam);
    }

    //转账回调处理
    public static function processNotify($biz_no, $status, $errmsg = null){
        global $DB;
        $order = $DB->find('transfer', '*', ['biz_no' => $biz_no]);
        if(!$order) {
            $order = $DB->find('settle', '*', ['transfer_no' => $biz_no]);
            if(!$order) return;
            if($status == 2 && $order['transfer_status'] == 1){
                $DB->update('settle', ['transfer_status'=>2, 'transfer_result'=>$errmsg, 'status'=>3, 'result'=>$errmsg], ['id' => $order['id']]);
            }elseif($status == 1 && $order['transfer_status'] == 2){
                $DB->update('settle', ['transfer_status'=>1, 'status'=>1, 'result'=>''], ['biz_no' => $biz_no]);
            }
            return;
        }
        if($status == 2 && $order['status'] == 0){ //转账失败
            self::failReservedTransfer($biz_no, $errmsg ?: '转账失败');
        }elseif($status == 1 && $order['status'] == 0){ //转账成功
            self::completeReservedTransfer($biz_no, ['status'=>1, 'paydate'=>'NOW()']);
        }
    }

    public static function red_add($uid, $type, $out_biz_no, $money, $desc = null, $channelid = null){
        global $conf, $DB, $userrow;
        $biz_no = $out_biz_no;
        if(strlen($biz_no)!=19 || !is_numeric($biz_no)) $biz_no = date("YmdHis").rand(11111,99999);

        if($uid > 0){
            if($conf['transfer_minmoney']>0 && $money<$conf['transfer_minmoney']) return ['code'=>-1, 'msg'=>'单笔最小代付金额限制为'.$conf['transfer_minmoney'].'元'];
            if($conf['transfer_maxmoney']>0 && $money>$conf['transfer_maxmoney']) return ['code'=>-1, 'msg'=>'单笔最大代付金额限制为'.$conf['transfer_maxmoney'].'元'];
        }
        
        if(!$channelid){
            if($type=='alipay'){
                $channelid = $conf['transfer_alipay'];
            }elseif($type=='wxpay'){
                $channelid = $conf['transfer_wxpay'];
            }else{
                return ['code'=>-1, 'msg'=>'type参数错误'];
            }
            if(!$channelid) return ['code'=>-1, 'msg'=>'未开启此转账方式'];
        }
        $channel = \lib\Channel::get($channelid, $userrow['channelinfo']);
        if(!$channel) return ['code'=>-1, 'msg'=>'当前支付通道信息不存在'];

        $trans = $DB->find('transfer', '*', ['out_biz_no' => $out_biz_no, 'uid' => $uid]);
        if($trans) return ['code'=>-1, 'msg'=>'该交易号已存在，请更换交易号'];

        $initialDepth = $DB->getTransactionDepth();
        if(!$DB->beginTransaction()) throw new \RuntimeException('Unable to start red packet reservation transaction: '.$DB->error());
        $need_money = null;
        try{
            if($uid > 0){
                $userrow = $DB->getRow('SELECT * FROM pre_user WHERE uid=:uid FOR UPDATE', [':uid'=>$uid]);
                if(!$userrow) throw new \RuntimeException('Merchant not found for red packet UID '.$uid);
                if($userrow['settle']==0){
                    self::rollbackToDepth($initialDepth);
                    return ['code'=>-1, 'msg'=>'您的商户出现异常，无法使用代付功能'];
                }
                $trans = $DB->find('transfer', '*', ['out_biz_no'=>$out_biz_no, 'uid'=>$uid]);
                if($trans){
                    self::rollbackToDepth($initialDepth);
                    return ['code'=>-1, 'msg'=>'该交易号已存在，请更换交易号'];
                }
                if($conf['settle_type']==1){
                    $today=date("Y-m-d").' 00:00:00';
                    $order_today=$DB->getColumn('SELECT SUM(realmoney) FROM pre_order WHERE uid=:uid AND tid<>2 AND status=1 AND endtime>=:today', [':uid'=>$uid, ':today'=>$today]);
                    if($order_today === false && $DB->error()) throw new \RuntimeException('Unable to calculate red packet balance: '.$DB->error());
                    if(!$order_today) $order_today = 0;
                    $enable_money=round($userrow['money']-$order_today,2);
                    if($enable_money<0)$enable_money=0;
                }else{
                    $enable_money=$userrow['money'];
                }
                if(!$conf['transfer_rate'])$conf['transfer_rate'] = $conf['settle_rate'];
                $need_money = round($money + $money*$conf['transfer_rate']/100,2);
                if($need_money>$enable_money){
                    self::rollbackToDepth($initialDepth);
                    return ['code'=>-1, 'msg'=>'需支付金额大于可转账余额'];
                }
            }

            $data = ['biz_no'=>$biz_no, 'out_biz_no'=>$out_biz_no, 'uid'=>$uid, 'type'=>$type, 'channel'=>$channelid, 'account'=>'', 'username'=>'', 'money'=>$money, 'costmoney'=>$need_money??$money, 'addtime'=>'NOW()', 'status'=>4, 'desc'=>$desc, 'result'=>'等待领取'];
            if($DB->insert('transfer', $data) === false) throw new \RuntimeException('Unable to reserve red packet '.$biz_no.': '.$DB->error());
            if($need_money>0) changeUserMoney2($uid, $userrow['money'], $need_money, false, '代付', $biz_no);
            if(!$DB->commit()) throw new \RuntimeException('Unable to commit red packet reservation '.$biz_no.': '.$DB->error());
        }catch(\Throwable $e){
            self::rollbackToDepth($initialDepth);
            throw $e;
        }

        $jumpurl = self::red_url($biz_no);
        $typename = $type == 'alipay' ? '支付宝' : ($type == 'wxpay' ? '微信' : '未知');
        return ['code'=>0, 'status'=>4, 'biz_no'=>$biz_no, 'out_biz_no'=>$out_biz_no, 'jumpurl'=>$jumpurl, 'cost_money'=>$need_money, 'msg'=>'红包创建成功！请在'.$typename.'打开 '.$jumpurl.' 确认收款。'];
    }

    public static function red_receive($biz_no, $openid, $submitter = null){
        global $DB;
        $initialDepth = $DB->getTransactionDepth();
        if(!$DB->beginTransaction()) throw new \RuntimeException('Unable to start red packet claim transaction: '.$DB->error());
        try{
            $trans = $DB->getRow('SELECT * FROM pre_transfer WHERE biz_no=:biz_no FOR UPDATE', [':biz_no'=>$biz_no]);
            if(!$trans){
                self::rollbackToDepth($initialDepth);
                return ['code'=>-1, 'msg'=>'红包不存在'];
            }
            if((int)$trans['status'] !== 4){
                self::rollbackToDepth($initialDepth);
                return ['code'=>-1, 'msg'=>(int)$trans['status']===1?'红包已领取':'红包状态异常，无法领取'];
            }
            $claimed = $DB->exec('UPDATE pre_transfer SET status=0,account=:account,result=:result WHERE biz_no=:biz_no AND status=4', [':account'=>$openid, ':result'=>'等待支付平台结果', ':biz_no'=>$biz_no]);
            if($claimed !== 1) throw new \RuntimeException('Unable to claim red packet '.$biz_no.': '.($DB->error() ?: 'unexpected affected row count'));
            if(!$DB->commit()) throw new \RuntimeException('Unable to commit red packet claim '.$biz_no.': '.$DB->error());
        }catch(\Throwable $e){
            self::rollbackToDepth($initialDepth);
            throw $e;
        }

        $channelinfo = null;
        if((int)$trans['uid'] > 0) $channelinfo = $DB->findColumn('user', 'channelinfo', ['uid'=>$trans['uid']]);
        $channel = \lib\Channel::get($trans['channel'], $channelinfo);
        if(!$channel){
            $DB->exec('UPDATE pre_transfer SET status=4,result=:result WHERE biz_no=:biz_no AND status=0', [':result'=>'支付通道不存在', ':biz_no'=>$biz_no]);
            return ['code'=>-1, 'msg'=>'当前支付通道信息不存在'];
        }

        try{
            $result = is_callable($submitter)
                ? call_user_func($submitter, $trans['type'], $channel, $biz_no, $openid, '', $trans['money'], $trans['desc'], $trans['type']=='alipay'?null:$trans['desc'])
                : self::submit($trans['type'], $channel, $biz_no, $openid, '', $trans['money'], $trans['desc'], $trans['type']=='alipay'?null:$trans['desc']);
        }catch(\Throwable $e){
            self::notePendingTransfer($biz_no, '红包结果待查询');
            error_log('[transfer reconciliation] '.$biz_no.' red packet provider exception: '.$e->getMessage());
            return ['code'=>-1, 'status'=>0, 'msg'=>'红包转账结果暂不明确，请稍后查询'];
        }

        if(($result['code'] ?? -1) == 0){
            $result['account'] = $openid;
            try{
                self::completeReservedTransfer($biz_no, $result);
            }catch(\Throwable $e){
                self::notePendingTransfer($biz_no, '红包本地结果待对账');
                error_log('[transfer reconciliation] '.$biz_no.' red packet completion failed: '.$e->getMessage());
                return ['code'=>-1, 'status'=>0, 'msg'=>'平台已受理，红包状态待对账'];
            }
            if(isset($result['wxpackage'])){
                $wxinfo = \lib\Channel::getWeixin($channel['appwxmp']);
                $result['wxtransfer'] = [
                    'mchId'=>$channel['appmchid'],
                    'appId'=>$wxinfo['appid'],
                    'package'=>$result['wxpackage'],
                ];
            }
            return $result;
        }

        if(!self::isExplicitProviderFailure($result)){
            self::notePendingTransfer($biz_no, '红包结果待查询: '.($result['msg'] ?? '未知响应'));
            return ['code'=>-1, 'status'=>0, 'msg'=>'红包转账结果暂不明确，请稍后查询'];
        }

        $message = mb_substr((string)($result['msg'] ?? '支付平台拒绝红包转账'), 0, 80);
        $released = $DB->exec('UPDATE pre_transfer SET status=4,result=:result WHERE biz_no=:biz_no AND status=0', [':result'=>$message, ':biz_no'=>$biz_no]);
        if($released !== 1){
            error_log('[transfer reconciliation] unable to release red packet '.$biz_no.': '.$DB->error());
            return ['code'=>-1, 'status'=>0, 'msg'=>'红包失败状态待对账'];
        }
        $result['status'] = 4;
        return $result;
    }

    public static function red_url($biz_no){
        global $siteurl;
        $t = time().'';
        $s = md5(SYS_KEY.$biz_no.$t.SYS_KEY);
        return $siteurl.'paypage/red.php?n='.$biz_no.'&t='.$t.'&s='.$s;
    }
}
