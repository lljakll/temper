<?php

/**
 * Command-line option parser for setup_db.php.
 */
class SetupDbCli
{
    public bool $help = false;
    public bool $check = false;
    public bool $dryRun = false;
    public bool $verbose = false;

    public static function parse(array $argv): self
    {
        $cli = new self();
        $args = array_slice($argv, 1);

        foreach ($args as $arg) {
            switch ($arg) {
                case '--help':
                case '-h':
                    $cli->help = true;
                    break;
                case '--check':
                case '--check-only':
                    $cli->check = true;
                    break;
                case '--dry-run':
                    $cli->dryRun = true;
                    break;
                case '--verbose':
                case '-v':
                    $cli->verbose = true;
                    break;
                default:
                    fwrite(STDERR, "Unknown option: {$arg}\n");
                    fwrite(STDERR, "Run 'php setup_db.php --help' for usage.\n");
                    exit(2);
            }
        }

        return $cli;
    }

    public static function printHelp(): void
    {
        $script = basename(__FILE__, '.php') === 'setup_cli' ? 'setup_db.php' : 'setup_db.php';

        echo <<<HELP
Church Treasurer System — Database Setup

Usage:
  php setup_db.php [options]

Options:
  -h, --help       Show this help message and exit
      --check      Validate schema + setup baseline version (read-only; no changes)
  -v, --verbose    Detailed output (use with --check)
      --dry-run    Show what full setup would do without modifying the database

Modes:
  (no options)     Run full database setup (destructive — drops and recreates all tables)
  --check          Structure validation plus setup baseline (v0.804) vs app_version report
  --check -v       Detailed per-table/column/permission validation output
  --dry-run        Preview drop/create steps for full setup without executing them

Notes:
  --check never runs destructive setup or applies updates/*.sql patches.
  If the database is behind the frozen setup baseline, --check warns that a full
  setup_db.php run (after backup) is required before applying newer patches.

Examples:
  php setup_db.php
  php setup_db.php --check
  php setup_db.php --check -v
  php setup_db.php --dry-run
  php setup_db.php --dry-run -v

HELP;
    }
}