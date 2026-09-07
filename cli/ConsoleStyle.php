<?php

namespace App;

use Symfony\Component\Console\Style\SymfonyStyle;

class ConsoleStyle extends SymfonyStyle {
    
    public function notice($message)
    {
        $this->block($message, 'Notice', 'fg=black;bg=#ebb134', ' ', true);
    }

    public function debug($message)
    {
        $this->block($message, 'debug');
    }

    public function infoWithTime($message)
    {
        $this->block(sprintf('(%s) %s', date('H:i:s T'), $message), 'INFO', 'fg=green', ' ', true);
    }
}