# Change Log
All notable changes to this project will be documented in this file.
This project adheres to [Semantic Versioning](http://semver.org/).

## [Unreleased]
### Added
- DF-911 Add support for upsert

## [0.2.0] - 2017-03-03
### Added
### Changed
- DF-967 Made the error message 'No record(s) detected in request.' more verbose
- When using 'ids' in URL or payload, always return a batch response, even for a single id
- Batch calls now consistently return errors in batch format use BatchException

### Fixed
- Fixed migrations with timestamp fields due to Laravel issue #11518 with some MySQL versions
- DF-934 Corrected parsing of negative integers in records coming from XML payloads

## [0.1.3] - 2017-01-20
### Fixed
- Correct the ServiceProvider namespace

## [0.1.2] - 2017-01-18
### Fixed
- Correct the update field check for virtual field

## [0.1.1] - 2017-01-16
### Changed
- Update dependencies for latest core

## 0.1.0 - 2015-10-24
First official release working with the new [dreamfactory](https://github.com/dreamfactorysoftware/dreamfactory) project.

[Unreleased]: https://github.com/dreamfactorysoftware/df-database/compare/0.2.0...HEAD
[0.2.0]: https://github.com/dreamfactorysoftware/df-database/compare/0.1.3...0.2.0
[0.1.3]: https://github.com/dreamfactorysoftware/df-database/compare/0.1.2...0.1.3
[0.1.2]: https://github.com/dreamfactorysoftware/df-database/compare/0.1.1...0.1.2
[0.1.1]: https://github.com/dreamfactorysoftware/df-database/compare/0.1.0...0.1.1
