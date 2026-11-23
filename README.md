# FILMUNITY Google sign-up and sign-in

Apply the app, database, routes, scripts and tests folders at the root of
the Laravel project. Review the two new routes in routes/web.php before
merging if you have edited that file since exporting the source.

## Guarded backend tests on Windows

In the PowerShell session with the three approved test database environment
variables already set, run these commands from the Laravel project root.
Composer updates composer.json and composer.lock locally.

```powershell
composer require "laravel/socialite:5.32.*"

.\vendor\bin\phpunit.bat --filter=TestDatabaseConfigurationTest
```

Check that the smoke test passes and prints database=filmunity_test. Then
apply the Google column migration using the included runner. It checks the
effective PostgreSQL settings and live database and role before running
only the new additive migration.

```powershell
php .\scripts\migrate-google-auth-test.php --env=testing
```

After the migration succeeds, run the suites individually. Each Laravel
test verifies the live PostgreSQL target. The Google tests simulate the
provider and use transaction rollback.

```powershell
.\vendor\bin\phpunit.bat --filter=GoogleAuthenticationTest
.\vendor\bin\phpunit.bat --filter=RegistrationTest
.\vendor\bin\phpunit.bat --filter=AuthenticationTest
```

## Connect the Google button for a browser check

1. Add this entry inside the return array in config/services.php.

   ```php
   'google' => [
       'client_id' => env('GOOGLE_CLIENT_ID'),
       'client_secret' => env('GOOGLE_CLIENT_SECRET'),
       'redirect' => env('GOOGLE_REDIRECT_URI'),
   ],
   ```

2. In Google Cloud, use a Web application OAuth client. Add the exact
   Laravel callback URL to Authorized redirect URIs. Example for a Laravel
   server running at http://localhost:8000:

   ```dotenv
   GOOGLE_CLIENT_ID=
   GOOGLE_CLIENT_SECRET=
   GOOGLE_REDIRECT_URI=http://localhost:8000/auth/google/callback
   ```

   Fill in the ID and secret in the Laravel .env. Keep those credentials
   server-side. If the Google consent screen is in testing mode, use an
   account listed as a test user. If Laravel caches config locally, run
   php artisan config:clear after adding the settings.

3. After the guarded tests pass, apply the same additive migration to the
   intended local browser-testing database through your normal Laravel
   migration workflow. The test runner above targets filmunity_test only.
4. Point the login and registration buttons to the Laravel
   /auth/google/redirect route using ordinary top-level navigation. For an
   Inertia page on the Laravel origin, use an anchor to that route. For a
   separate frontend, target Laravel's full URL and keep the redirect and
   callback on the same Laravel origin. The existing Fortify login response
   determines the post-login destination, currently the Laravel dashboard
   in the supplied source. Connecting a separate member dashboard requires
   whatever Laravel session integration that frontend uses.

Try a fresh Gmail account, then sign out and sign in with Google again.
An existing email/password account still signs in with its original
credentials. The callback does not automatically link accounts by email.
A Google account using a third-party email address must complete
FILMUNITY email verification. Accounts with Fortify two-factor protection
continue through the Fortify challenge.
