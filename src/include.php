<?php
if(PHP_SAPI =='cli'){
    \opencmf\core\Console::$type['worker'] = [
        'behavior' => '\opencmf\workerman\Workerman::run',
        'description' => '以Workerman模式运行，例如 php opencmf worker start'
    ];
}
