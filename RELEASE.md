# v1.6.67 — API keys are generated randomly

## Security

- **API keys created in the same second were identical, across organizations.** A key was derived from its creation time and row id, but the id is never loaded after insert (the primary key is the uuid), so every key created in the same second got the same value. API authentication resolves a key to the first matching credential, so a key issued to one organization could authenticate as another's. Keys are now 32 random characters from the CSPRNG, for new keys and for rolled keys. (#283)

## Upgrade Steps

- Check for existing duplicate keys and roll every credential that shares one, in both the live and sandbox databases:
  ```sql
  SELECT `key`, COUNT(*) AS credentials, COUNT(DISTINCT company_uuid) AS orgs
  FROM api_credentials WHERE deleted_at IS NULL
  GROUP BY `key` HAVING COUNT(*) > 1;
  ```
