---
date: 2026-08-29
git_commit: af90ff316
branch: feat/provenance-metadata
topic: "Which recovered scans are provably the photos lost on 2026-08-29"
tags: [recovery, provenance, incident, scans]
status: complete
---

# Recovered scans: what is proven and what is not

The gallery's image rows and every original under `upload/2026/04/19/` were lost on
2026-08-29 (see [decision 0011](../decisions/0011-provenance-suites-require-a-throwaway-install.md)).
105 PNGs were staged in `_temp/` and copied into `galleries/`, in four folders named
after the scan batches. All 105 copies were verified byte-identical to the staged files.

## Method

The only surviving evidence of the lost photos is the derivative cache,
`_data/i/upload/2026/04/19/` — 157 files covering **76 distinct originals**, all from the
19 April upload batch. It is the *whole* cache: no other directory exists under `_data/i`.

Matching ran in two stages:

1. **Signature pass.** Every staged file and every aspect-preserving derivative reduced to
   a 24x24 grayscale signature, then nearest-neighbour by Euclidean distance. The result was
   cleanly bimodal — 62 candidates below distance 3, 43 above 15, nothing in between.
2. **Exact pass.** Each of the 62 candidates resized to its derivative's exact dimensions and
   compared with ImageMagick RMSE. **All 62 scored 0** — pixel-identical.

Anti-vacuity checks: the metric was first run against a deliberate non-pair, which scores
~16000 on the same scale, so a zero is not something every comparison produces. Two matches
and two non-matches were additionally compared by eye. No derivative was claimed by two
different staged files.

## Result

| Folder | Staged | Proven | Unproven |
|---|---|---|---|
| `galleries/PHOTO_ALBUM` | 16 | 16 | 0 |
| `galleries/Sefferweich_Allgemein_Fotos` | 56 | 37 | 19 |
| `galleries/Verschiedenes` | 15 | 9 | 6 |
| `galleries/1992_Rund_um_Sefferweich` | 18 | 0 | 18 |
| **total** | **105** | **62** | **43** |

**62 of the 76 originals the cache knows about are recovered. 14 are not** — they exist only
as derivatives of at most ~600px.

## What the unproven 43 are

Unproven means *no surviving derivative to compare against*, not *shown to be different*.
The file dates split the set exactly:

- **All 62 proven files have an April mtime.** All 31 files with a June mtime are unproven.
- The remaining 12 unproven files have April mtimes but no matching derivative.

The cache holds only the 19 April batch, so anything scanned or edited in June could not be
matched by this method whether or not it was ever in the gallery. Nothing here says these 43
were *not* in it; the evidence needed to decide simply does not exist any more.

## Unproven files

    1992_Rund_um_Sefferweich_01_0.png
    1992_Rund_um_Sefferweich_02_0.png
    1992_Rund_um_Sefferweich_02_1.png
    1992_Rund_um_Sefferweich_02_2.png
    1992_Rund_um_Sefferweich_03_0.png
    1992_Rund_um_Sefferweich_03_1.png
    1992_Rund_um_Sefferweich_03_2.png
    1992_Rund_um_Sefferweich_03_3.png
    1992_Rund_um_Sefferweich_03_4.png
    1992_Rund_um_Sefferweich_04_0.png
    1992_Rund_um_Sefferweich_05_0.png
    1992_Rund_um_Sefferweich_05_1.png
    1992_Rund_um_Sefferweich_05_2.png
    1992_Rund_um_Sefferweich_05_3.png
    1992_Rund_um_Sefferweich_06_0.png
    1992_Rund_um_Sefferweich_06_1.png
    1992_Rund_um_Sefferweich_06_2.png
    1992_Rund_um_Sefferweich_06_3.png
    Sefferweich_Allgemein_Fotos_030_0.png
    Sefferweich_Allgemein_Fotos_037_0.png
    Sefferweich_Allgemein_Fotos_038_0.png
    Sefferweich_Allgemein_Fotos_043_0.png
    Sefferweich_Allgemein_Fotos_045_0.png
    Sefferweich_Allgemein_Fotos_048_0.png
    Sefferweich_Allgemein_Fotos_049_0.png
    Sefferweich_Allgemein_Fotos_074_0.png
    Sefferweich_Allgemein_Fotos_130_0.png
    Sefferweich_Allgemein_Fotos_131_1.png
    Sefferweich_Allgemein_Fotos_133_0.png
    Sefferweich_Allgemein_Fotos_134_0.png
    Sefferweich_Allgemein_Fotos_135_0.png
    Sefferweich_Allgemein_Fotos_137_0.png
    Sefferweich_Allgemein_Fotos_137_1.png
    Sefferweich_Allgemein_Fotos_137_2.png
    Sefferweich_Allgemein_Fotos_138_0.png
    Sefferweich_Allgemein_Fotos_138_1.png
    Sefferweich_Allgemein_Fotos_139_0.png
    Verschiedenes001_0.png
    Verschiedenes001_1.png
    Verschiedenes001_2.png
    Verschiedenes003_2.png
    Verschiedenes005_1.png
    Verschiedenes007_2.png
