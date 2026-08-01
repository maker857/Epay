<?php

namespace lib;

class ChannelInput
{
    public static function nullable($value)
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string)$value);
        return $value === '' ? null : $value;
    }
}
