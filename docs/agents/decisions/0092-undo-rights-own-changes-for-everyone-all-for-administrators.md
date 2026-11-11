# 0092: Undo rights: own changes for everyone, all changes for administrators

- Date: 2026-11-11
- Change: `.agents/changes/2026-10-20-picture-page-edit-ux`, task 07

## Decision

`pwg.photoinfo.undo` lets every logged-in account undo its own date, info and tag entries of a photo
it can see, and lets `is_admin()` (webmaster included) undo anyone's. A guest is refused; a photo
the caller cannot see is answered like a missing entry (404), as decision 0044 does for saves.

The refusals, in this order: not allowed (403: not the caller's entry, an undo entry, a photo entry,
a field without undo yet), already undone (409), changed meanwhile (409, naming the account of the
newest later entry on that value, "jemand anderem" when no entry says who).

## Reason

Requested explicitly. Several people edit the same photos; an undo must never overwrite a later
change, so it runs only while the current value is the entry's new one.

## Trade-offs

- A shared account with core's `generic` status counts as one user: anyone using it can undo its
  changes.
- The check and the write are not one transaction: two undos or an undo and a save in the same
  instant can both pass the check. The log stays append-only and the file ends in the state of the
  last writer; not locked further, as the saves themselves are not.
- The change list's Rückgängig links repeat the rights rule in `history.js`; the server answers
  every click regardless.
- Region entries are refused until task 08 adds their branch.
