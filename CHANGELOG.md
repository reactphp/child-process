# Changelog

## 0.4.2 (2017-03-10)

* Feature: Forward compatibility with Stream v0.5
  (#26 by @clue)

* Improve test suite by removing AppVeyor and adding PHPUnit to `require-dev`
  (#27 and #28 by @clue)

## 0.4.1 (2016-08-01)

* Standalone component
* Test against PHP 7 and HHVM, report test coverage, AppVeyor tests
* Wait for stdout and stderr to close before watching for process exit
  (#18 by @mbonneau)

## 0.4.0 (2014-02-02)

* Feature: Added ChildProcess to run async child processes within the event loop (@jmikola)
