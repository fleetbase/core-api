# API Throttling Configuration

This document explains how to configure API throttling for different environments and use cases.

## Overview

The Fleetbase API includes a configurable throttling middleware that supports two bypass mechanisms:

1. **Global Toggle** (Option 1): Disable throttling completely via environment variable
2. **Unlimited API Keys** (Option 3): Specific API keys that bypass throttling

## Configuration Options

### Environment Variables

Add these to your `.env` file:

```bash
# Option 1: Global enable/disable
THROTTLE_ENABLED=true                    # Set to false to disable throttling

# Throttle limits (when enabled)
THROTTLE_REQUESTS_PER_MINUTE=120         # Max requests per minute
THROTTLE_DECAY_MINUTES=1                 # Time window in minutes

# Option 3: Unlimited API keys (comma-separated)
THROTTLE_UNLIMITED_API_KEYS=Bearer test_key_123,Bearer load_test_456
```

## Use Cases

### Development Environment

Disable throttling for easier development:

```bash
# .env.local
THROTTLE_ENABLED=false
```

### Performance Testing (k6, JMeter, etc.)

**Option A**: Disable throttling globally

```bash
# .env.staging
THROTTLE_ENABLED=false
```

**Option B**: Use unlimited API keys

```bash
# .env.staging
THROTTLE_ENABLED=true
THROTTLE_UNLIMITED_API_KEYS=Bearer k6_test_key_xyz123
```

Then in your k6 script:

```javascript
const HEADERS = {
    'Content-Type': 'application/json',
    'Authorization': 'Bearer k6_test_key_xyz123',
};
```

### Production Environment

Keep throttling enabled with normal limits:

```bash
# .env.production
THROTTLE_ENABLED=true
THROTTLE_REQUESTS_PER_MINUTE=120
THROTTLE_DECAY_MINUTES=1
```

For production testing, use unlimited API keys:

```bash
# .env.production
THROTTLE_ENABLED=true
THROTTLE_UNLIMITED_API_KEYS=Bearer prod_test_key_secure_abc789
```

## Security Considerations

### ⚠️ Important Warnings

1. **Never disable throttling in production** unless using unlimited API keys
2. **Keep unlimited API keys secret** - treat them like passwords
3. **Rotate unlimited API keys regularly**
4. **Monitor usage** of unlimited API keys via logs
5. **Remove test keys** after performance testing is complete

### Logging

The middleware automatically logs:

- When throttling is disabled globally (in production)
- When unlimited API keys are used
- IP addresses and request paths

Check your logs for security monitoring:

```bash
# View throttling-related logs
tail -f storage/logs/laravel.log | grep -i throttl
```

## Examples

### Example 1: k6 Performance Test Script

```bash
#!/bin/bash
# run-k6-tests.sh

# Disable throttling
export THROTTLE_ENABLED=false
php artisan config:clear

# Run tests
k6 run tests/k6/performance-test.js

# Re-enable throttling
export THROTTLE_ENABLED=true
php artisan config:clear
```

### Example 2: Production Testing with Unlimited Keys

```bash
# Generate a secure test key
TEST_KEY="Bearer prod_test_$(openssl rand -hex 16)"

# Add to .env
echo "THROTTLE_UNLIMITED_API_KEYS=$TEST_KEY" >> .env
php artisan config:clear

# Use in your test tool
curl -X GET "https://api.fleetbase.io/v1/test" \
  -H "Authorization: $TEST_KEY"

# Remove after testing
sed -i '/THROTTLE_UNLIMITED_API_KEYS/d' .env
php artisan config:clear
```

### Example 3: Multiple Test Keys

```bash
# For different testing scenarios
THROTTLE_UNLIMITED_API_KEYS=Bearer k6_load_test,Bearer selenium_test,Bearer manual_qa_test
```

## Troubleshooting

### Issue: Configuration not taking effect

```bash
# Clear all caches
php artisan config:clear
php artisan cache:clear
php artisan route:clear
```

### Issue: Still getting 429 errors

```bash
# Check current configuration
php artisan tinker
>>> config('api.throttle.enabled')
=> false

>>> config('api.throttle.unlimited_keys')
=> ["Bearer test_key_123"]
```

### Issue: Unlimited key not working

Make sure:
1. The key matches exactly (including "Bearer " prefix if used)
2. Configuration cache is cleared
3. The key is in the correct format in `.env`

```bash
# Correct formats:
THROTTLE_UNLIMITED_API_KEYS=Bearer abc123
THROTTLE_UNLIMITED_API_KEYS=Bearer abc123,Bearer xyz789
```

## Testing the Implementation

### Test 1: Verify throttling is disabled

```bash
export THROTTLE_ENABLED=false
php artisan config:clear

# Should not throttle even with 200 requests
for i in {1..200}; do
  curl -X GET "http://localhost/api/v1/test" \
    -H "Authorization: Bearer YOUR_TOKEN" &
done
wait
```

### Test 2: Verify unlimited key works

```bash
export THROTTLE_ENABLED=true
export THROTTLE_UNLIMITED_API_KEYS="Bearer test_unlimited_key"
php artisan config:clear

# Should not throttle with unlimited key
for i in {1..200}; do
  curl -X GET "http://localhost/api/v1/test" \
    -H "Authorization: Bearer test_unlimited_key" &
done
wait
```

### Test 3: Verify normal throttling works

```bash
export THROTTLE_ENABLED=true
export THROTTLE_REQUESTS_PER_MINUTE=10
php artisan config:clear

# Should throttle after 10 requests
for i in {1..20}; do
  curl -X GET "http://localhost/api/v1/test" \
    -H "Authorization: Bearer normal_key"
done
```

## Best Practices

1. ✅ Use environment-specific `.env` files
2. ✅ Document which keys are for testing
3. ✅ Set up alerts for when throttling is disabled in production
4. ✅ Rotate unlimited API keys regularly
5. ✅ Remove test keys after testing is complete
6. ✅ Use high limits instead of disabling in production when possible
7. ✅ Monitor logs for unusual patterns

## Support

For questions or issues:
- Check the logs: `storage/logs/laravel.log`
- Review this documentation
- Contact the development team
