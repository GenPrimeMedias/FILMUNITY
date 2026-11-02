# UNI360 — Laravel Application Structure

This is the recommended target structure after generating a fresh Laravel 13 React starter-kit application. Preserve framework defaults unless a clear need justifies moving files.

```text
uni360/
├── app/
│   ├── Console/
│   ├── Exceptions/
│   ├── Http/
│   │   ├── Controllers/
│   │   │   ├── Platform/
│   │   │   └── Tenant/
│   │   ├── Middleware/
│   │   └── Requests/
│   ├── Models/
│   │   ├── User.php
│   │   └── ...
│   ├── Modules/
│   │   ├── Platform/
│   │   ├── Organisations/
│   │   ├── IdentityAccess/
│   │   ├── Membership/
│   │   ├── DigitalIdentity/
│   │   ├── Governance/
│   │   ├── Welfare/
│   │   ├── Grievances/
│   │   ├── Finance/
│   │   ├── ProfessionalDirectory/
│   │   ├── MeetingsEvents/
│   │   ├── Elections/
│   │   ├── Communications/
│   │   ├── Documents/
│   │   ├── Compliance/
│   │   └── Reporting/
│   ├── Policies/
│   └── Providers/
├── bootstrap/
├── config/
├── database/
│   ├── factories/
│   ├── migrations/
│   └── seeders/
├── docs/
│   ├── architecture/
│   ├── database/
│   └── ...
├── resources/
│   ├── js/
│   │   ├── components/
│   │   ├── hooks/
│   │   ├── layouts/
│   │   ├── lib/
│   │   ├── pages/
│   │   ├── types/
│   │   └── modules/
│   ├── css/
│   └── views/
├── routes/
│   ├── web.php
│   ├── console.php
│   └── modules.php
├── storage/
├── tests/
│   ├── Feature/
│   ├── Unit/
│   ├── Security/
│   └── Architecture/
├── .github/
├── AGENTS.md
├── composer.json
├── package.json
└── ...
```

## Module internal pattern

A module should have a clear boundary and should not become a miniature framework. Start with only the folders needed:

```text
app/Modules/Membership/
├── Actions/
│   ├── SubmitMembershipApplication.php
│   └── ApproveMembershipApplication.php
├── Data/
├── Enums/
├── Events/
├── Exceptions/
├── Models/
├── Policies/
├── Queries/
├── Rules/
└── Support/
```

HTTP controllers and Form Requests can remain in `app/Http` and delegate to module Actions. This preserves Laravel conventions while making domain boundaries visible.

## React page structure

```text
resources/js/
├── components/
│   ├── ui/
│   └── shared/
├── layouts/
│   ├── platform/
│   └── organisation/
├── pages/
│   ├── auth/
│   ├── platform/
│   └── tenant/
├── modules/
│   ├── organisations/
│   ├── membership/
│   └── ...
├── hooks/
├── lib/
└── types/
```

Keep reusable primitives in `components`, domain-specific UI in `modules`, and route/page entry points in `pages`.

## Namespace and autoloading

Laravel's default `App\\` PSR-4 mapping points to `app/`. A class in `app/Modules/Membership/Actions/ApproveMembershipApplication.php` can use the namespace:

```php
namespace App\Modules\Membership\Actions;
```

Do not add custom Composer autoload mappings unless the project deliberately moves code outside the default `app/` namespace.

## Module dependency rules

- Platform and shared infrastructure may be depended on by domain modules.
- Domain modules should not reach into another module's internal implementation.
- Cross-module interactions should use public Actions, Events or explicitly defined interfaces.
- Avoid circular dependencies.
- Keep shared enums and utilities small; do not turn `Shared` into a dumping ground.
- Add architecture tests or static analysis rules as the module count grows.
