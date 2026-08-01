<?php

require_once __DIR__ . '/../includes/lib/ChannelInput.php';

assert(lib\ChannelInput::nullable('') === null);
assert(lib\ChannelInput::nullable('  ') === null);
assert(lib\ChannelInput::nullable('1.25') === '1.25');
assert(lib\ChannelInput::nullable(null) === null);

echo "Channel input normalization test passed.\n";
