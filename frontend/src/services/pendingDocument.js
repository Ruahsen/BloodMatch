/**
 * In-memory staging for the valid ID attached during registration.
 *
 * Document upload (POST /api/profile/documents) requires an authenticated
 * session, but registration (POST /api/register) intentionally creates no
 * session. So the Step 2 file cannot be uploaded until the registrant
 * verifies their email and is signed in. This module holds the File
 * reference across the register -> verify-email SPA navigation (same tab,
 * no reload). If the tab reloads the reference is lost by design — the
 * verify page then falls back to asking the user to attach it in Profile.
 */

let pending = null // { file: File, docType: string } | null

export function setPendingDocument(file, docType = 'national_id') {
  pending = file ? { file, docType } : null
}

export function getPendingDocument() {
  return pending
}

export function clearPendingDocument() {
  pending = null
}
