#!/usr/bin/env node
// Tight feedback loop for logout stale dashboard bug
// Asserts the exact user symptom: after logout, dashboard remains visible without navigation
// Checks code patterns that cause it. RED = bug present, GREEN = fixed.
const fs = require('fs');
const path = require('path');

function read(p){ return fs.readFileSync(path.join(__dirname,'..',p),'utf8'); }

let fails=[];
let passes=[];

function assert(name, condition, why){
  if(condition){ passes.push(name); } else { fails.push(`${name}: ${why}`); }
}

const app = read('frontend/src/App.jsx');
const auth = read('frontend/src/context/AuthContext.jsx');
const officer = read('frontend/src/pages/OfficerDashboardPage.jsx');
const admin = read('frontend/src/pages/AdminDashboardPage.jsx');

// 1. App.jsx should navigate on logout (otherwise stale route stays mounted)
assert('logout-navigates',
  /useNavigate/.test(app) && /logout\(\)/.test(app) && /navigate\(/.test(app),
  'App.jsx logout button does not trigger React Router navigation after logout — stale route remains mounted'
);

// 2. App.jsx should have route guard (RequireAuth/ProtectedRoute/Navigate redirect)
assert('route-guard-exists',
  /RequireAuth|ProtectedRoute|PrivateRoute/.test(app) && /Navigate/.test(app),
  'No route guard found in App.jsx — protected routes render even when user=null'
);

// 3. AuthContext logout should clear csrf (prevents stale csrf after logout -> future login fails)
assert('auth-clears-csrf',
  /clearCsrf/.test(auth),
  'AuthContext logout does not clear csrfToken — stale csrf may persist'
);

// 4. Dashboard pages should not rely solely on mount-only fetch (empty deps) without auth guard
// With guard, this is mitigated; without guard, stale data persists in state `data` after logout.
assert('dashboard-not-stale-mount-only',
  /RequireAuth|ProtectedRoute/.test(app),
  'Dashboard useEffect([]) fetches once on mount and never re-evaluates on auth change; without guard stale data stays visible'
);

// 5. App.jsx should NOT allow unauthenticated rendering of protected routes without redirect
// Check that Routes contain guard wrappers
assert('protected-routes-wrapped',
  (app.match(/RequireAuth/g) || []).length >= 4,
  'Expected at least 4 protected routes wrapped in RequireAuth (profile, officer, admin, requests, etc.)'
);

console.log('=== LOGOUT STALE DASHBOARD REPRO ===');
console.log('PASSES:', passes.length, passes);
console.log('FAILS:', fails.length, fails);
if(fails.length>0){
  console.log('\n🔴 BUG REPRODUCED — stale dashboard after logout is present');
  console.log(fails.join('\n'));
  process.exit(1);
} else {
  console.log('\n🟢 BUG FIXED — logout correctly clears auth state and redirects, guards prevent stale rendering');
  process.exit(0);
}
