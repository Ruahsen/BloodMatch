# AGENTS.md

This file is the development instruction file for AI coding agents working on this repository.

The agent must **ALWAYS read and follow [CONTEXT.md](./CONTEXT.md)** before making architectural or feature decisions.

---

## Development Rules

### Requirements & Scope
1. Treat `CONTEXT.md` as the source of truth for the BloodMatch requirements.
2. Inspect the existing codebase before modifying anything.
3. Never rewrite working features unnecessarily.
4. Never invent requirements.
5. Never remove an existing requirement unless explicitly instructed.
6. Preserve the existing authentication, authorization, security, and database architecture unless there is a documented reason to change it.

### Stack
7. Use HTML, CSS, JavaScript, React, PHP, MySQL, and XAMPP.
8. MySQL uses port **3307**.
9. Do not use Laravel unless explicitly instructed.

### Security
10. Use secure password hashing.
11. Use prepared SQL statements.
12. Validate and sanitize input.
13. Use backend authorization, not just frontend UI restrictions.
14. Protect authenticated routes and sensitive API endpoints.
15. Preserve CSRF protection where applicable.
16. Do not expose secrets, passwords, API keys, SMTP credentials, or database credentials in frontend code.

### Domain Logic & Data
17. Keep blood compatibility/matching logic centralized and maintainable.
18. Do not allow proximity to override blood compatibility.
19. Treat medical compatibility as a system-defined matching rule and not as a replacement for professional medical confirmation.
20. Avoid duplicate notifications.
21. Preserve audit logging.
22. Maintain data integrity and use database transactions where appropriate.

### Code Quality & Conventions
23. Follow the existing project's folder structure and coding conventions.
24. Reuse existing components and services when appropriate.
25. Avoid unnecessary dependencies.

### UX/UI
26. Keep the interface responsive.
27. Maintain the black-and-white visual identity while supporting light and dark mode.
28. Prioritize accessibility, usability, performance, and maintainability.

### Verification & Process
29. Before implementing a feature, identify which functional/non-functional requirement it satisfies.
30. After modifying code, test the affected functionality.
31. Do not claim a feature is implemented unless you actually verify it in the code.
32. If a requirement conflicts with the existing implementation, stop and explain the conflict before making a destructive architectural change.
33. When uncertain about an existing implementation, inspect the repository instead of guessing.

---

## Implementation Truth Rule

**CONTEXT.md defines what BloodMatch is required to do.**
**The repository defines what BloodMatch currently does.**

Never assume a feature is implemented because:

- it is described in `CONTEXT.md`
- it appears in an old plan
- it appears in conversation history
- a UI element exists
- an endpoint name exists
- a database table exists

A feature is only considered implemented after verifying its actual end-to-end behavior in the repository.

Use the following status labels:

| Label | Meaning |
|---|---|
| ✅ IMPLEMENTED AND VERIFIED | Verified by inspecting actual behavior in the repository |
| ⚠️ PARTIALLY IMPLEMENTED | Some verified subset exists; gaps documented |
| ❌ NOT IMPLEMENTED | No verified implementation exists |
| ❓ CANNOT VERIFY | Exists but could not be verified end-to-end |

Do not upgrade a feature to ✅ without evidence from the actual implementation.

---

## Development Priority

```
Correctness → Security → Requirements → Existing functionality
           → Data integrity → Performance → UX/UI → Maintainability
```
