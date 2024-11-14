<?php

namespace App\Commands\Tine;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use App\ConsoleStyle;

class TineReinstallCommand extends TineCommand
{
    protected function configure()
    {
        parent::configure();

        $this
            ->setName('tine:reinstall')
            ->setDescription('reinstall tine')
            ->setHelp('');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        parent::execute($input, $output);

        $io = new ConsoleStyle($input, $output);

        $result_code = $this->tineUninstall($io, null);
        if (0 !== $result_code) {
            return $result_code;
        }
        
        return $this->tineInstall($io, null);;
    }
}