# Rotation Service Spec

## Purpose

This document describes the external HTTP contract for Passway rotation services used by dynamic secrets.

Current goals:

- let setup-admin register a global rotation service by base URL
- let organization admins create integrations using service-defined credential fields
- let users create dynamic secrets using service-defined input fields
- let the rotation service provision, validate, and rotate secret material

## High-Level Model

There are three layers of data.

1. Rotation service
- globally registered by setup-admin
- identified by base URL
- exposes `GET /health` and `GET /spec`

2. Organization integration
- belongs to one organization
- references one registered rotation service
- stores encrypted organization-level credentials
- credentials are validated against the service's `integration_schema`

3. Dynamic secret
- belongs to one organization directory
- references one organization integration
- stores service-defined secret input fields
- stores one primary secret value plus optional readonly output fields

## Endpoints

Base URL example:

```text
https://rotator.example.internal
```

Required endpoints:

1. `GET /health`
2. `GET /spec`
3. `POST /provision`
4. `POST /validate`
5. `POST /rotate`

## Health Check

### Request

```http
GET /health
Accept: application/json
```

### Success response

```json
{
  "status": "ok"
}
```

Passway considers the service healthy only when:

- HTTP status is `2xx`
- JSON body contains `{"status":"ok"}`

## Spec Endpoint

### Request

```http
GET /spec
Accept: application/json
```

### Response shape

```json
{
  "version": "1.0",
  "service": {
    "name": "SSH Rotator",
    "capabilities": {
      "provision": true,
      "rotate": true,
      "validate": true
    }
  },
  "integration_schema": {
    "fields": []
  },
  "secret_schema": {
    "fields": []
  },
  "output_schema": {
    "primary_secret_field": "private_key",
    "fields": []
  }
}
```

## Field Schema

Each schema field is an object like:

```json
{
  "name": "username",
  "label": "Username",
  "type": "string",
  "required": true,
  "placeholder": "deploy",
  "help_text": "Linux account name"
}
```

Supported field types currently used by Passway UI/backend:

- `string`
- `integer`
- `boolean`
- `enum`
- `secret_text`
- `readonly_text`
- `textarea`

### Enum field

Enum options may be objects:

```json
{
  "name": "account_mode",
  "label": "Account mode",
  "type": "enum",
  "required": true,
  "options": [
    { "value": "existing_user", "label": "Existing user" },
    { "value": "create_user", "label": "Create user" }
  ]
}
```

## Schema Semantics

### `integration_schema`

Organization-level credentials required by the service.

Examples:

- host
- port
- login
- private key
- API token

These values are entered by org admins and stored encrypted by Passway.

### `secret_schema`

Per-secret inputs required to provision and rotate a specific managed resource.

Examples:

- username
- database name
- account mode
- target role

These values are entered once when the dynamic secret is created.
They are not intended for manual editing later.

### `output_schema`

Fields returned by the service after provision and rotate.

`primary_secret_field` is required and must point to one of the output fields.

The field referenced by `primary_secret_field` becomes the encrypted main secret value stored in `secrets.encrypted_value`.

Other output fields are stored as dynamic secret metadata and may be shown in the UI as readonly values.

Examples:

- `private_key` as primary secret
- `public_key` as readonly output
- `fingerprint` as readonly output

## Provision

Used to create the initial secret material for a new dynamic secret.

### Request

```http
POST /provision
Accept: application/json
Content-Type: application/json
```

```json
{
  "credentials": {
    "host": "server1.example.internal",
    "port": 22,
    "login": "root",
    "private_key": "-----BEGIN OPENSSH PRIVATE KEY----- ..."
  },
  "input": {
    "username": "deploy",
    "account_mode": "existing_user"
  },
  "context": {
    "secret_uuid": "generated-by-passway",
    "secret_name": "Deploy SSH Key",
    "organization_id": "123",
    "directory_uuid": "dir-uuid"
  }
}
```

### Success response

```json
{
  "outputs": {
    "private_key": "-----BEGIN OPENSSH PRIVATE KEY----- ...",
    "public_key": "ssh-ed25519 AAAA...",
    "fingerprint": "SHA256:..."
  }
}
```

Passway expects:

- HTTP status `2xx`
- JSON object with non-empty `outputs`
- `outputs[primary_secret_field]` must exist and be a non-empty string

After `/provision`, Passway immediately calls `/validate` before saving the new dynamic secret.

## Validate

Used to verify that the current outputs still represent a valid working secret.

### Request

```http
POST /validate
Accept: application/json
Content-Type: application/json
```

```json
{
  "credentials": {
    "host": "server1.example.internal",
    "port": 22,
    "login": "root",
    "private_key": "-----BEGIN OPENSSH PRIVATE KEY----- ..."
  },
  "secret": {
    "uuid": "secret-uuid",
    "name": "Deploy SSH Key",
    "type": "dynamic",
    "version": 2,
    "directory_id": "5"
  },
  "input": {
    "username": "deploy",
    "account_mode": "existing_user"
  },
  "outputs": {
    "private_key": "-----BEGIN OPENSSH PRIVATE KEY----- ...",
    "public_key": "ssh-ed25519 AAAA...",
    "fingerprint": "SHA256:..."
  }
}
```

### Success response

```json
{
  "valid": true
}
```

Passway expects:

- HTTP status `2xx`
- JSON body containing boolean-like `valid`

## Rotate

Used to replace the current outputs with new outputs.

### Request

```http
POST /rotate
Accept: application/json
Content-Type: application/json
```

```json
{
  "credentials": {
    "host": "server1.example.internal",
    "port": 22,
    "login": "root",
    "private_key": "-----BEGIN OPENSSH PRIVATE KEY----- ..."
  },
  "secret": {
    "uuid": "secret-uuid",
    "name": "Deploy SSH Key",
    "type": "dynamic",
    "version": 2,
    "directory_id": "5"
  },
  "input": {
    "username": "deploy",
    "account_mode": "existing_user"
  },
  "current_outputs": {
    "private_key": "old-private-key",
    "public_key": "old-public-key",
    "fingerprint": "SHA256:old"
  }
}
```

### Success response

```json
{
  "outputs": {
    "private_key": "new-private-key",
    "public_key": "new-public-key",
    "fingerprint": "SHA256:new"
  }
}
```

Passway expects:

- HTTP status `2xx`
- JSON object with non-empty `outputs`
- `outputs[primary_secret_field]` must exist and be a non-empty string

## Rotate Validation Flow In Passway

When rotating a dynamic secret, Passway does the following:

1. calls `/validate` on current outputs and expects `valid=true`
2. calls `/rotate`
3. calls `/validate` on old outputs and expects `valid=false`
4. calls `/validate` on new outputs and expects `valid=true`
5. stores new primary secret and readonly outputs locally

If any step after `/rotate` fails, Passway attempts a best-effort rollback.

## Best-Effort Rollback Convention

Passway may call `POST /rotate` again with rollback markers.

### Rollback request shape

```json
{
  "credentials": { ... },
  "secret": { ... },
  "input": { ... },
  "current_outputs": {
    "private_key": "new-private-key",
    "public_key": "new-public-key"
  },
  "target_outputs": {
    "private_key": "old-private-key",
    "public_key": "old-public-key"
  },
  "rollback": true
}
```

Support for rollback is recommended but not strictly required.
Passway treats rollback as best-effort only.

## Validation Rules Implemented By Passway

### For `integration_schema`

Passway currently validates:

- required fields must be present
- `integer` must be parseable as integer
- `boolean` must be parseable as boolean
- `enum` must match one of declared options
- string-like fields must be scalar values

### For `secret_schema`

Same validation rules as above.

## Example SSH Spec

```json
{
  "version": "1.0",
  "service": {
    "name": "SSH Rotator",
    "capabilities": {
      "provision": true,
      "rotate": true,
      "validate": true
    }
  },
  "integration_schema": {
    "fields": [
      {
        "name": "host",
        "label": "Host",
        "type": "string",
        "required": true,
        "placeholder": "server1.example.internal"
      },
      {
        "name": "port",
        "label": "Port",
        "type": "integer",
        "required": false,
        "default": 22,
        "placeholder": "22"
      },
      {
        "name": "login",
        "label": "Login",
        "type": "string",
        "required": true,
        "placeholder": "root"
      },
      {
        "name": "private_key",
        "label": "Private key",
        "type": "secret_text",
        "required": true,
        "help_text": "Private key used by the rotator to connect to the server"
      }
    ]
  },
  "secret_schema": {
    "fields": [
      {
        "name": "username",
        "label": "Username",
        "type": "string",
        "required": true,
        "placeholder": "deploy"
      },
      {
        "name": "account_mode",
        "label": "Account mode",
        "type": "enum",
        "required": true,
        "options": [
          { "value": "existing_user", "label": "Existing user" },
          { "value": "create_user", "label": "Create user" }
        ]
      }
    ]
  },
  "output_schema": {
    "primary_secret_field": "private_key",
    "fields": [
      {
        "name": "private_key",
        "label": "Private key",
        "type": "secret_text",
        "required": true
      },
      {
        "name": "public_key",
        "label": "Public key",
        "type": "readonly_text",
        "required": true
      },
      {
        "name": "fingerprint",
        "label": "Fingerprint",
        "type": "readonly_text",
        "required": false
      }
    ]
  }
}
```

## Notes

- Dynamic secret input fields are intended to be immutable from the Passway UI after creation.
- Manual user action for dynamic secrets is rotation trigger only.
- Template secrets are outside this protocol and do not use external rotation services.
- Passway currently stores output fields other than the primary secret as metadata and may display them in the secret details page.
