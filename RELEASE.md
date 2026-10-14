# v1.6.70 — Text a verification code to the number being verified

## Changed

- **`VerificationCode::generateSmsVerificationFor()` takes a `phone` option.** The code is texted to that number instead of the subject's own, and the SMS guard counts and traces the send against it. A customer changing their phone number now gets the code on the new number, including a customer with no number on file, who previously got nothing while the request reported success. Without the option nothing changes: the subject's phone is used, and a subject with no phone still gets a saved code and no text.

## Upgrade Steps

- None. The option is additive.
- fleetbase/storefront v0.4.25 requires this release (`fleetbase/core-api ^1.6.70`) for its phone change endpoint.
