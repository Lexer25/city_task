@echo off
chcp 65001 > nul
rem Task checkAnalitCode (module task)
rem Default analit codes: 501,502,503,504,505,506
rem Safe mode by default: --dry-run=1 (no INSERT)
rem Real run: run_checkAnalitCode.bat --dry-run=0
rem Usage: run_checkAnalitCode.bat [--code=501,502,503] [--dry-run=0|1]
c:\xampp\php\php.exe c:\xampp\htdocs\city\modules\minion\minion --task=checkAnalitCode %*
