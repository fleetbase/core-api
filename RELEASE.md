> v1.6.59 ~ "Verification and credentials email can render again"

---
## Highlights
Both mail templates failed to compile, so **every verification and credentials email threw instead of sending**. Any flow that delivers a code — customer signup, SMS login with email fallback, password reset, account closure, driver login — returned a 400 carrying a Blade parse error.

Shipped in v1.6.56 and present in v1.6.57 and v1.6.58. Upgrade if you are on any of those.

---
## Bug Fixes
- **`verification.blade.php` and `user-credentials.blade.php` did not parse.** The greeting read `Good Morning@if($user->name), ...@endif`, and Blade only treats `@` as a directive when the preceding character is **not** a word character — the rule that keeps `foo@bar.com` from compiling. So the `@if` was left as literal text while its `@endif` compiled anyway, leaving an unmatched `endif` that broke the enclosing `if/elseif/else`:

  ```
  syntax error, unexpected token "else", expecting end of file
  (View: .../core-api/views/mail/verification.blade.php)
  ```

  The greeting is now built in one expression, so no directive sits against a word.

---
## Testing
- Added a test that compiles **every** Blade view in the package and runs `php -l` over the result. Nothing caught the original break because no test ever compiled a view — the templates were only exercised through mocked mailers, which never render them.

---
## Upgrade Steps
No migration and no configuration change. If verification emails were failing, they will work again once this is deployed; no codes need reissuing.

---
## Need help?
- [GitHub Discussions](https://github.com/fleetbase/fleetbase/discussions)
- [Discord](https://discord.gg/HnTqQ6zAVn)
