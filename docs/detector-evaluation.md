# Offline detector evaluation

Run `python scripts/evaluate-detector.py predictions.jsonl --output evaluation.json`.
The runner is local and makes no provider calls. Supply one JSON object per line with:

- `sample_id`: unique, nonempty identifier.
- `group_id`: source/author grouping; all related excerpts and rewrites must have the same group. The runner rejects groups crossing splits.
- `split`: `train`, `validation` or `test`; metrics use test records only.
- `language`, `domain`: explicit nonempty slice labels (use en/bn/hi/ur/ar/ne for the six product languages).
- `provider`, `model`: exact version identity; a file must contain one pair.
- `label`: independently established `human`, `mixed` or `ai` ground truth.
- `probabilities`: object with numeric `human`, `mixed`, `ai` values in [0,1], summing to one. Do not divide a single confidence score into invented class probabilities.

No raw draft text is required. Keep dataset provenance, consent/license and labelling review separately. Assign splits before model tuning; use author/source families for grouping, including rewritten derivatives. This script can only validate declared groups, not discover undeclared duplicates or certify labels. Include representative samples in every language/domain and all three classes before drawing conclusions.

Outputs contain sample counts, actual-row/predicted-column confusion matrices, per-class precision and recall, human-to-AI false-positive rate, unnormalized multiclass Brier score (sum of three squared probability errors, averaged over samples), and ten equal-width top-label confidence bins with empirical accuracy. Undefined class ratios are null. Ties use human, mixed, ai order and are counted explicitly. Provider errors or missing probabilities must be accounted for separately as coverage failures, never inserted as zero scores. Small or biased samples are not evidence of deployment accuracy. There is no aggregate cross-provider score.

`python scripts/test-detector-evaluation.py` runs synthetic unit fixtures, not a real language benchmark. A trained proprietary detector, licensed independently labelled evaluation corpus, confidence intervals and actual independent language validation remain unfinished.
