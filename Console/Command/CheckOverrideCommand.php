<?php declare(strict_types=1);

namespace Yireo\ThemeOverrideChecker\Console\Command;

use Exception;
use Magento\Framework\App\State;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NotFoundException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Finder\Finder;
use Yireo\ThemeOverrideChecker\Exception\ThemeFileResolveException;
use Yireo\ThemeOverrideChecker\Util\FileComparison;
use Yireo\ThemeOverrideChecker\Util\SplFileInfoFactory;
use Yireo\ThemeOverrideChecker\Util\ThemeFileResolver;
use Yireo\ThemeOverrideChecker\Util\ThemeProvider;

class CheckOverrideCommand extends Command
{
    private Finder $finder;
    private ThemeFileResolver $themeFileResolver;
    private ThemeProvider $themeProvider;
    private FileComparison $fileComparison;
    private State $appState;

    public function __construct(
        Finder $finder,
        ThemeFileResolver $themeFileResolver,
        ThemeProvider $themeProvider,
        FileComparison $fileComparison,
        State $appState,
        string $name = null
    ) {
        parent::__construct($name);
        $this->finder = $finder;
        $this->themeFileResolver = $themeFileResolver;
        $this->themeProvider = $themeProvider;
        $this->fileComparison = $fileComparison;
        $this->appState = $appState;
    }

    /**
     * Initialization of the command.
     */
    protected function configure()
    {
        $this->setName('yireo:theme-overrides:check')
            ->setDescription('Check the overrides of a specified theme')
            ->addArgument('theme', InputOption::VALUE_REQUIRED, 'Theme name');
    }

    /**
     * CLI command description.
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return void
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $themeName = $input->getArgument('theme');
        if (empty($themeName)) {
            $output->writeln('<error>No theme argument given</error>');

            return Command::FAILURE;
        }

        $this->appState->setAreaCode('frontend');
        $theme = $this->themeProvider->getTheme($themeName);

        try {
            $themePath = $this->themeProvider->getThemePath($theme);
        } catch (NotFoundException|LocalizedException $e) {
            $output->writeln('<error>No theme path found for theme "'.$themeName.'"</error>');

            return Command::FAILURE;
        }

        $output->writeln('Theme path found: '.$themePath);
        try {
            $parentTheme = $this->themeProvider->getParentThemeFromTheme($theme);
            $output->writeln('Theme parent found: '.$parentTheme->getPath());
        } catch (NotFoundException $e) {
            $output->writeln('<error>No theme parent found</error>');

            return Command::FAILURE;
        }

        $table = new Table($output);
        $table->setHeaders([
            'Theme file',
            'Parent theme file',
            'Number of different lines',
        ]);

        $themeFiles = $this->finder->in($themePath)->files();
        foreach ($themeFiles as $themeFile) {
            $parentThemeFile = null;
            $lineDiff = 0;
            $lineCountDiff = 0;
            $percentage = 0;

            try {
                $parentThemeFile = $this->themeFileResolver->resolveOriginalFile($themeFile, $theme);
                $lineDiff = $this->fileComparison->getLineDifference($parentThemeFile, $themeFile);
                $lineCountDiff = $this->fileComparison->getLineCountDifference($parentThemeFile, $themeFile);
                $percentage = $this->fileComparison->getPercentageDifference($parentThemeFile, $themeFile);
            } catch (ThemeFileResolveException $e) {
            }

            $themeCell = $themeFile->getRelativePathname();

            $parentCell = 'No parent file found';
            if ($parentThemeFile !== null) {
                $parentCell = $parentThemeFile->getRelativePathname();
            }

            if ($lineDiff < 1) {
                $lineDiff = $lineCountDiff;
            }

            $diffCell = '<info>No differences</info>';
            if ($lineDiff < 1 && $parentThemeFile !== null) {
                $diffCell = '<comment>Possibly no override needed</comment>';
            }

            if ($lineDiff > 0) {
                $diffCell = '<error>Found '.$lineDiff.' lines to be different</error>';
                //$diffCell = '<error>Found '.$lineDiff.' lines ('.$percentage.'%) to be different</error>';
            }

            $table->addRow([
                $themeCell,
                $parentCell,
                $diffCell,
            ]);
        }

        $table->render();

        return Command::SUCCESS;
    }
}
