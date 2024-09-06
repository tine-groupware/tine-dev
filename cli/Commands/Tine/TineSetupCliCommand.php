<?php

namespace App\Commands\Tine;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputArgument;

class TineSetupCliCommand extends TineCommand
{
    protected function configure()
    {
        parent::configure();

        $this
            ->setName('tine:setup')
            ->setDescription('executes setup.php with command, dont use the --config option')
            ->setHelp('')
            ->addArgument('options', InputArgument::REQUIRED, 'Options')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        parent::execute($input, $output);

        $cmd = trim($input->getArgument('options'), '"');
        passthru($this->getComposeString() . ' exec --user tine20 web sh -c "cd /usr/share/tine20/ && php setup.php  ' . $cmd . '"', $result_code);

        return $result_code;
    }    
}