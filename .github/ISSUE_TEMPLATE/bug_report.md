---
name: Bug Report
about: Report a bug or unexpected behavior
title: "[BUG] "
labels: bug
assignees: ''
---

## Description

A clear and concise description of the bug.

## Steps to Reproduce

```php
$client = new ManagedClient();
// Minimal code to reproduce the issue
```

## Expected Behavior

What you expected to happen.

## Actual Behavior

What actually happened. Include error messages or exceptions if applicable.

## Environment

- PHP version:
- Library version:
- opcua-client version:
- OPC UA server: (e.g., open62541, Prosys, Unified Automation, etc.)
- OS:

## Daemon Configuration

- Socket path:
- Auth token: (yes/no)
- Cache driver: (memory/file/none)
- Log level:

## Security Configuration

- SecurityPolicy: (e.g., None, Basic256Sha256)
- SecurityMode: (e.g., None, SignAndEncrypt)
- Authentication: (e.g., Anonymous, Username/Password, Certificate)

## Additional Context

Any additional context, daemon logs, or stack traces.
