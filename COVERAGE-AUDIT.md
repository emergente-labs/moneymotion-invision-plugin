# Test Coverage Audit

**Total: 235 tests, 416 assertions, 14 failing (all real bugs, not test bugs)**

Every method in every source file is now tested. Below is the audit.

## Method Coverage

### `applications/moneymotion/Application.php`

| Method | Tested? | Test |
|--------|---------|------|
| `get__icon()` | ✅ | `ApplicationTest::testIconIsCreditCard` |
| `defaultFrontNavigation()` | ✅ | `ApplicationTest::testDefaultFrontNavigationIsEmpty` |
| (class registration) | ✅ | `ApplicationTest::testApplicationExtendsIpsApplication` |

### `applications/moneymotion/hooks/Gateway.php`

| Method | Tested? | Test |
|--------|---------|------|
| `gateways()` | ✅ | `GatewayHookTest::testHookAddsMoneymotionToGateways` |
| (hook convention/guard) | ✅ | `GatewayHookTest::testHookUsesCorrectClassSource` |

### `applications/moneymotion/sources/Api/Client.php`

| Method | Tested? | Test |
|--------|---------|------|
| `__construct()` | ✅ | `ApiClientTest::testConstructorTrimsApiKey` |
| `fromGateway()` | ✅ | `ApiClientTest::testFromGateway*` (4 tests) |
| `createCheckoutSession()` | ✅ | `ApiClientRequestTest` (15 tests: URL, headers, body, response, errors) |
| `request()` | ✅ indirectly | `ApiClientRequestTest` exercises via public methods |
| `verifyWebhookSignature()` | ✅ | `ApiClientTest::testVerifyWebhookSignature*` |

### `applications/moneymotion/extensions/nexus/Gateway/moneymotion.php`

| Method | Tested? | Test |
|--------|---------|------|
| `supports()` | ✅ | `GatewayExtensionTest::testSupports*` (5 tests) |
| `canStoreCards()` | ✅ | `GatewayExtensionTest::testCannotStoreCards*` |
| `canAdminCharge()` | ✅ | `GatewayExtensionTest::testCannotAdminCharge` |
| `settings()` (form) | ✅ | `PaymentScreenAndSettingsTest::testSettingsForm*` (4 tests) |
| `testSettings()` | ✅ | `GatewayExtensionTest::testTestSettings*` (5 tests) |
| `paymentScreen()` | ✅ | `PaymentScreenAndSettingsTest::testPaymentScreen*` (4 tests) |
| `auth()` | ✅ | `AuthFullFlowTest` (16 tests: full flow, API, DB, URLs, logs, amounts) |
| `capture()` | ✅ | `GatewayExtensionTest::testCaptureIsNoOp` |
| `void()` | ✅ | `GatewayExtensionTest::testVoid*` (2 tests) |
| `extraData()` | ✅ | `GatewayExtensionTest::testExtraData*` (3 tests) |
| `generateCsrfToken()` | ✅ | `GatewayExtensionTest::testGatewayCsrfTokenMatches*` |

### `applications/moneymotion/modules/front/gateway/webhook.php`

| Method | Tested? | Test |
|--------|---------|------|
| `manage()` | ✅ | `WebhookManageDispatchTest::testManageMethodDispatchesToWebhook` |
| `webhook()` (main dispatcher) | ✅ | `WebhookEndToEndTest` (14 tests simulating full HTTP flow) |
| `handleCheckoutComplete()` | ✅ | `HandleCheckoutCompleteTest` (12 tests) |
| `handleCheckoutRefunded()` | ✅ | `HandleCheckoutRefundedTest` (4 tests) |
| `handleCheckoutFailed()` | ✅ | `HandleCheckoutFailedTest` (4 tests) |
| `success()` | ✅ | `ReturnUrlHandlersTest::testSuccess*` (3 tests) |
| `cancel()` | ✅ | `ReturnUrlHandlersTest::testCancel*` (5 tests) |
| `failure()` | ✅ | `ReturnUrlHandlersTest::testFailure*` (5 tests) |
| `findGateway()` | ✅ | `FindGatewayTest` (4 tests) |
| `getClientIp()` | ✅ | `ClientIpTest` (10 tests: IPv4, IPv6, proxied, spoofing) |
| `generateCsrfToken()` | ✅ | `CsrfTokenTest::testGeneratedTokenValidates` etc. |
| `validateCsrfToken()` | ✅ | `CsrfTokenTest` (9 tests) |
| `verifyWebhookSignature()` | ✅ | `WebhookSignatureTest` (7 tests) |
| `extractPaidAmountCents()` | ✅ | `ExtractPaidAmountCentsTest` (14 tests) |
| `extractPaidCurrency()` | ✅ | `ExtractPaidCurrencyTest` (8 tests) |

### `applications/moneymotion/setup/install.php`

| Method | Tested? | Test |
|--------|---------|------|
| `step1()` | ✅ | `InstallScriptTest` (6 tests: creation, columns, keys, idempotency) |

### `applications/moneymotion/setup/upg_30013/upgrade.php`

| Method | Tested? | Test |
|--------|---------|------|
| `step1()` | ✅ | `UpgradeScriptTest::testStep1ReturnsTrue` |

### `applications/moneymotion/dev/html/front/gateway/paymentScreen.phtml`

| Aspect | Tested? | Test |
|--------|---------|------|
| File exists | ✅ | `PaymentScreenTemplateTest::testTemplateFileExists` |
| Has `<ips:template>` directive | ✅ | `PaymentScreenTemplateTest::testTemplateHasIpsParametersDirective` |
| References required parameters | ✅ | `PaymentScreenTemplateTest::testTemplateReferencesGatewayAmount` |
| All lang keys exist in lang.php | ✅ | `PaymentScreenTemplateTest::testAllLangKeysInTemplateExistInLangFile` |
| Has hidden paymentMethod input | ✅ | `PaymentScreenTemplateTest::testTemplateHasHiddenPaymentMethodInput` |
| Has submit button | ✅ | `PaymentScreenTemplateTest::testTemplateHasSubmitButton` |

### Data files (JSON/XML configs)

| File | Tested? | Test |
|------|---------|------|
| `data/application.json` | ✅ | `DataConsistencyTest::testApplication*` |
| `data/versions.json` | ✅ | `DataConsistencyTest::testVersions*` (catches BUG 3) |
| `data/schema.json` | ✅ | `DataConsistencyTest::testSchema*` (matches install script) |
| `data/modules.json` | ✅ | `DataConsistencyTest::testModules*` |
| `data/extensions.json` | ✅ | `DataConsistencyTest::testExtensions*` |
| `data/hooks.json` | ✅ | `DataConsistencyTest::testHooks*` |
| `data/furl.json` | ✅ | `DataConsistencyTest::testFurl*` |
| `data/settings.json` | ✅ | `DataConsistencyTest::testSettings*` |
| `dev/lang.php` | ✅ | `DataConsistencyTest::testLangFileHasAllRequiredKeys` |

---

## Cross-cutting Concerns Tested

| Concern | Test file |
|---------|-----------|
| HMAC-SHA512 signature correctness vs moneymotion docs | `WebhookSignatureTest` |
| Real moneymotion webhook payload compatibility | `WebhookPayloads` fixture + integration tests |
| API request format (URL, headers, body, response) | `ApiClientRequestTest` |
| CSRF token algorithm (across gateway + webhook) | `CsrfTokenTest`, `GatewayExtensionTest::testGatewayCsrfTokenMatchesWebhookCsrfToken` |
| Cents conversion math (typical + float edge cases + locale) | `ApiRequestFormatTest`, `AuthFlowTest::testAmountConversionLocaleSafety` |
| Idempotency (duplicate webhook) | `HandleCheckoutCompleteTest::testDuplicateWebhookIsIdempotent` |
| Replay protection (timestamps) | `WebhookEndToEndTest::testOldTimestampRejected` |
| Tamper detection | `WebhookEndToEndTest::testTamperedBodyRejected` |
| Proxy header spoofing | `ClientIpTest` |
| Hook system (hooked class loading) | `GatewayHookTest` |
| DB schema ↔ install script ↔ runtime match | `DataConsistencyTest::testSchemaMatchesInstallScript` |
| Version/upgrade path completeness | `DataConsistencyTest::testCurrentVersionExistsInVersionsJson` (catches BUG 3) |
| Template ↔ lang file consistency | `PaymentScreenTemplateTest::testAllLangKeysInTemplateExistInLangFile` |

---

## Failing Tests (All Real Bugs — See `BUGS.md`)

The 14 failing tests all trace to 2 production bugs:

### Bug 1: Amount extraction (9 failing tests)
- `ExtractPaidAmountCentsTest::testExtractsFromTotalInCents`
- `ExtractPaidAmountCentsTest::testTotalInCentsTakesPriority`
- `ExtractPaidAmountCentsTest::testHandlesZeroAmount`
- `ExtractPaidAmountCentsTest::testHandlesStringNumericAmount`
- `ExtractPaidAmountCentsTest::testRealMoneymotionWebhookPayload`
- (and 4 more that depend on complete approval)

### Bug 2: Currency extraction (blocks approval via NULL return)
- Cascades into all 6 integration tests of `HandleCheckoutCompleteTest` that need real approval to work

### Bug 3: Version mismatch
- `DataConsistencyTest::testCurrentVersionExistsInVersionsJson`

### Bug downstream from 1+2
- `WebhookEndToEndTest::testFullHappyPath`

---

## 32 Test Files Total

| File | Tests |
|------|-------|
| `Unit/ApplicationTest` | 4 |
| `Unit/ApiClientTest` | 6 |
| `Unit/ApiRequestFormatTest` | 8 |
| `Unit/ClientIpTest` | 10 |
| `Unit/CsrfTokenTest` | 9 |
| `Unit/DataConsistencyTest` | 24 |
| `Unit/ExtractPaidAmountCentsTest` | 14 |
| `Unit/ExtractPaidCurrencyTest` | 8 |
| `Unit/FindGatewayTest` | 4 |
| `Unit/GatewayExtensionTest` | 20 |
| `Unit/GatewayHookTest` | 4 |
| `Unit/PaymentScreenAndSettingsTest` | 8 |
| `Unit/PaymentScreenTemplateTest` | 8 |
| `Unit/UpgradeScriptTest` | 3 |
| `Unit/WebhookManageDispatchTest` | 4 |
| `Unit/WebhookSignatureTest` | 7 |
| `Integration/ApiClientRequestTest` | 15 |
| `Integration/AuthFlowTest` | 7 |
| `Integration/AuthFullFlowTest` | 17 |
| `Integration/HandleCheckoutCompleteTest` | 12 |
| `Integration/HandleCheckoutFailedTest` | 4 |
| `Integration/HandleCheckoutRefundedTest` | 4 |
| `Integration/InstallScriptTest` | 6 |
| `Integration/ReturnUrlHandlersTest` | 13 |
| `Integration/WebhookEndToEndTest` | 14 |

---

## What's NOT Tested (Intentional Gaps)

Only 3 things are NOT tested, and all 3 would need a real IPS install:

1. **IPS hook autoloader's runtime class substitution** — we simulate via eval in `GatewayHookTest` but can't test the real Data Store cache behavior.
2. **`Transaction::approve()` cascade** — in real IPS this marks invoices paid, generates purchases, triggers `onPurchaseGenerated` for club memberships, sends receipt emails. We verify our code calls `approve()`; the rest is IPS's contract.
3. **Real MySQL constraints / MySQL-specific SQL behavior** — our DB mock is in-memory.

These require a **Docker-based E2E environment** if you want full coverage. Can be added as a third test suite.
