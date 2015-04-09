<?php
/*
* This file is part of the OrbitaleTranslationBundle package.
*
* (c) Alexandre Rock Ancelet <contact@orbitale.io>
*
* For the full copyright and license information, please view the LICENSE
* file that was distributed with this source code.
*/

namespace Orbitale\Bundle\TranslationBundle\Command;

use Orbitale\Bundle\TranslationBundle\Translation\Extractor;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * This command extracts translation elements from database to files.
 */
class TranslationExtractCommand extends ContainerAwareCommand
{
    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setName('orbitale:translation:extract')
            ->setDefinition(array(
                new InputArgument('locale', InputArgument::REQUIRED, 'The locale'),
                new InputOption(
                    'output-format', 'f', InputOption::VALUE_OPTIONAL,
                    'Override the default output format', 'yml'
                ),
                new InputOption(
                    'output-directory', 'o', InputOption::VALUE_OPTIONAL,
                    'Sets up the output directory <comment>(default : app/Resources/translations/)</comment>'
                ),
                new InputOption(
                    'keep-files', null, InputOption::VALUE_NONE,
                    'By default, all existing files are overwritten. Turn on this option to keep existing files.'
                ),
                new InputOption(
                    'dirty', 'd', InputOption::VALUE_NONE,
                    'Extracts even non-translated elements'
                )
            ))
            ->setDescription('Extracts the database translations into files')
            ->setHelp(<<<EOF
The <info>%command.name%</info> command extracts translation strings from database
and writes files in the configured output directory.
EOF
            )
        ;
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {

        $dirty = $input->getOption('dirty');
        $keepFiles = $input->getOption('keep-files');
        $outputFormat = $input->getOption('output-format');
        $outputDirectory = $input->getOption('output-directory');
        $locale = $input->getArgument('locale');

        $verbosity = $output->getVerbosity();

        /** @var Extractor $extractor */
        $extractor = $this->getContainer()->get('orbitale.translation.extractor');

        $extractor->cli($output);

        $outputCheck = $extractor->checkOutputDir($outputDirectory, true);

        if (1 < $verbosity) {
            if (preg_match('#_exists$#isUu', $outputCheck)) {
                $method = 'Retrieved';
            } else {
                $method = 'Created';
            }

            $from = preg_replace('#^([^_]+)_.*$#isUu', '$1', $outputCheck);

            $output->writeln('<info>'.$method.'</info> output directory from <info>'.$from.'</info>.');
        }

        if (1 < $verbosity) {
            $output->writeln('Using following output directory : <info>'.$outputDirectory.'</info>');
        }

        $errorMessage = '';
        $done = false;

        // Lancement de la commande du service d'extraction de traductions
        try {
            $done = $extractor->extract($locale, $outputFormat, $outputDirectory, $keepFiles, $dirty);
        } catch (\Exception $e) {
            while ($e) {
                $errorMessage .= "\n".$e->getMessage();
                $e = $e->getPrevious();
            }
        }

        if (!$errorMessage && $done) {
            $output->writeln('Done!');
            return 0;
        } else {
            $output->writeln("An error has occurred, please check your configuration and datas.".$errorMessage);
            return 1;
        }
    }
}
