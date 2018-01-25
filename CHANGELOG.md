# Change Log
All notable changes to this project will be documented in this file.
This project adheres to [Semantic Versioning](http://semver.org/).

## [Unreleased]
## [0.9.1] - 2018-01-25
### Added
- DF-1275 Initial support for multi-column constraints

## [0.9.0] - 2017-12-26
### Added
- DF-1252 GraphQL support
- DF-1224 Added ability to set different default limits (max_records_returned) per service
- DF-1186 Add exceptions for missing data when generating relationships
- Added package discovery
### Changed
- DF-1150 Update copyright and support email
- Correct designation for HAS_ONE relationship
- Cleanup use of checkServicePermission, use ServiceManager where applicable

## [0.8.1] - 2017-11-21
### Fixed
- Correct designation for HAS_ONE relationship

## [0.8.0] - 2017-11-03
### Changed
- Change getNativeDateTimeFormat to handle column schema to detect detailed datetime format
- Upgraded Swagger to OpenAPI 3.0 specification
- Reduced repeated method calls
- DF-1184 Limit schema object displayed fields when discovery is not complete

## [0.7.0] - 2017-09-15
### Added
- DF-1060 Support for data retrieval (GET) caching and configuration
- Add new support for HAS_ONE relationship to schema management
### Fixed
- DF-1160 Correct resource name usage for procedures and functions when pulling parameters
- Allow refresh request option to pass down through the layers
- Cleanup primary and unique key handling

## [0.6.1] - 2017-08-30
### Added
- Support for list, set, map, and tuple data types

## [0.6.0] - 2017-08-17
- Removed direct use of Service model, using ServiceManager
- Cleaned up connection usage and correcting swagger definition
- Rework schema interface for database services in order to better control caching
- Bug fixes for service caching

## [0.5.1] - 2017-08-01
### Fixed
- Updating a single or multiple fields through _field resource should not be allowed to delete fields.
 
## [0.5.0] - 2017-07-27
### Fixed
- DF-269 Creating Belongs_To and Many_Many relationship records correctly
- Separating base schema from SQL schema
- Datetime settings handling

## [0.4.0] - 2017-06-05
### Fixed
- Cleanup - removal of php-utils dependency
- DF-1105 Fix migration for MS SQL Server possible cascading issue
- Fix count_only param usage

## [0.3.0] - 2017-04-21
### Added
- DF-911 Add support for upsert
### Fixed
- DF-1033 Correct datetime config option usage
- DF-1008 Correct inconsistent behavior regarding selected fields and related data

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

[Unreleased]: https://github.com/dreamfactorysoftware/df-database/compare/0.9.1...HEAD
[0.9.1]: https://github.com/dreamfactorysoftware/df-database/compare/0.9.0...0.9.1
[0.9.0]: https://github.com/dreamfactorysoftware/df-database/compare/0.8.1...0.9.0
[0.8.1]: https://github.com/dreamfactorysoftware/df-database/compare/0.8.0...0.8.1
[0.8.0]: https://github.com/dreamfactorysoftware/df-database/compare/0.7.0...0.8.0
[0.7.0]: https://github.com/dreamfactorysoftware/df-database/compare/0.6.1...0.7.0
[0.6.1]: https://github.com/dreamfactorysoftware/df-database/compare/0.6.0...0.6.1
[0.6.0]: https://github.com/dreamfactorysoftware/df-database/compare/0.5.1...0.6.0
[0.5.1]: https://github.com/dreamfactorysoftware/df-database/compare/0.5.0...0.5.1
[0.5.0]: https://github.com/dreamfactorysoftware/df-database/compare/0.4.0...0.5.0
[0.4.0]: https://github.com/dreamfactorysoftware/df-database/compare/0.3.0...0.4.0
[0.3.0]: https://github.com/dreamfactorysoftware/df-database/compare/0.2.0...0.3.0
[0.2.0]: https://github.com/dreamfactorysoftware/df-database/compare/0.1.3...0.2.0
[0.1.3]: https://github.com/dreamfactorysoftware/df-database/compare/0.1.2...0.1.3
[0.1.2]: https://github.com/dreamfactorysoftware/df-database/compare/0.1.1...0.1.2
[0.1.1]: https://github.com/dreamfactorysoftware/df-database/compare/0.1.0...0.1.1
