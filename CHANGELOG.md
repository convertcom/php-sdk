## 1.0.0 (2026-04-27)

### Features

* automated release pipeline with semantic-release ([552dc6e](https://github.com/convertcom/php-sdk/commit/552dc6e91d06d053a38b7ef3f59392499959df48))
* **utils:** add LogUtils::toLoggable helper for safe logging of OpenAPI models ([d90e56a](https://github.com/convertcom/php-sdk/commit/d90e56a2118aedfcc59fa9416ca0311f0dd1cee9)), closes [#11](https://github.com/convertcom/php-sdk/issues/11)

### Bug Fixes

* ConvertSDK test update ([d4340d6](https://github.com/convertcom/php-sdk/commit/d4340d6c80f4cfa5888706b332699e57df0d75af))
* **data,experience,bucketing,api:** wrap remaining log contexts via LogUtils::toLoggable ([3d22905](https://github.com/convertcom/php-sdk/commit/3d22905f2af4e9a33c84fc72b66c7628e6b72f4e)), closes [#11](https://github.com/convertcom/php-sdk/issues/11)
* integration test updates, CI and README fixes ([67ea9fe](https://github.com/convertcom/php-sdk/commit/67ea9fe5d3621208c8fc6a09148e650dd97f0991))
* **release:** migrate rollover-version-plugin to conventional-commits-parser v6 API ([9ff166a](https://github.com/convertcom/php-sdk/commit/9ff166a583a19df7ce67b64ceef5b7626e3e6d55))
* **release:** repair Packagist publishing pipeline ([d56be10](https://github.com/convertcom/php-sdk/commit/d56be107cde6da238a47dc974fe097a680e7e39a))
* **release:** use node-modules linker for semantic-release compatibility ([47dddf1](https://github.com/convertcom/php-sdk/commit/47dddf1f275058849d59f0dd97b98c4f5f2afd3b))
* rollover version plugin and release docs update ([8fd48d9](https://github.com/convertcom/php-sdk/commit/8fd48d9f2c1bb9ed2914ea90ba11566a95bf324e))
* **rules:** wrap log context to prevent OpenAPI enum serialization error ([e6ea094](https://github.com/convertcom/php-sdk/commit/e6ea094abb0b0e60defab1096175d6289a682966)), closes [#11](https://github.com/convertcom/php-sdk/issues/11) [#11](https://github.com/convertcom/php-sdk/issues/11)
* test fixes and cleanup ([692664b](https://github.com/convertcom/php-sdk/commit/692664b22fe8d6b3598993e79fc525f64c50e0e0))
* update CI workflow configuration ([caa6917](https://github.com/convertcom/php-sdk/commit/caa69179af03af7f5e8ef123281d37eb65ee3db2))
* update full-chain integration test ([463c69a](https://github.com/convertcom/php-sdk/commit/463c69a36f632772115b702703847c9a37b972fe))

### Refactoring

* reorganize Types package — move OpenAPI models to Generated/ ([a143a1d](https://github.com/convertcom/php-sdk/commit/a143a1d60df2aa22d26b71c0f2666c9b503b4473))
* **sdk:** address adversarial-review findings on PR [#29](https://github.com/convertcom/php-sdk/issues/29) ([1d9d751](https://github.com/convertcom/php-sdk/commit/1d9d7515c1b4cd5e0ae8a45cee23cf1864cfe0dd))
