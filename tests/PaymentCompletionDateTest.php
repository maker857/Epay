<?php
declare(strict_types=1);

require dirname(__DIR__) . '/includes/lib/Payment.php';

$valid = \lib\Payment::normalizeCompletionTime('2026-08-01 23:45:10');
if ($valid !== ['endtime' => '2026-08-01 23:45:10', 'date' => '2026-08-01']) {
    fwrite(STDERR, "valid completion time was not normalized correctly\n");
    exit(1);
}

if (\lib\Payment::normalizeCompletionTime('not-a-date') !== null) {
    fwrite(STDERR, "invalid completion time should be ignored\n");
    exit(1);
}

if (\lib\Payment::normalizeCompletionTime(null) !== null) {
    fwrite(STDERR, "empty completion time should be ignored\n");
    exit(1);
}

$source = file_get_contents(dirname(__DIR__) . '/includes/lib/Payment.php');
if ($source === false || strpos($source, "\$date['date']") !== false) {
    fwrite(STDERR, "stale date variable assignment remains\n");
    exit(1);
}

echo "Payment completion date tests passed." . PHP_EOL;
