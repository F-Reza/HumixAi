"""Offline held-out evaluation of labelled detector probabilities; no network calls."""
import argparse
import json
import math
from collections import defaultdict
from pathlib import Path

LABELS = ('human', 'mixed', 'ai')


def evaluate(rows):
    seen, groups, versions = set(), {}, set()
    for row in rows:
        for key in ('sample_id', 'group_id', 'language', 'domain', 'provider', 'model'):
            if not isinstance(row.get(key), str) or not row[key].strip():
                raise ValueError('Missing nonempty field: ' + key)
        if row['sample_id'] in seen:
            raise ValueError('Duplicate sample_id')
        seen.add(row['sample_id'])
        if row.get('split') not in ('train', 'validation', 'test') or row.get('label') not in LABELS:
            raise ValueError('Invalid split or label')
        group = row['group_id']
        if group in groups and groups[group] != row['split']:
            raise ValueError('Group leakage between splits')
        groups[group] = row['split']
        versions.add((row['provider'], row['model']))
        p = row.get('probabilities', {})
        if set(p) != set(LABELS) or any(type(v) not in (float, int) or not math.isfinite(v) or not 0 <= v <= 1 for v in p.values()):
            raise ValueError('Expected human/mixed/ai finite probabilities in [0,1]')
        if not math.isclose(sum(p.values()), 1, abs_tol=1e-6):
            raise ValueError('Probabilities must sum to one')
    if len(versions) != 1:
        raise ValueError('Evaluate one provider/model version per file')
    heldout = [r for r in rows if r['split'] == 'test']
    if not heldout:
        raise ValueError('No held-out test samples')
    def metrics(samples):
        matrix = {a: {b: 0 for b in LABELS} for a in LABELS}
        bins = [{'count': 0, 'confidence_sum': 0, 'correct': 0} for _ in range(10)]
        brier = 0
        ties = 0
        for r in samples:
            p = r['probabilities']; confidence = max(p.values())
            winners = [k for k in LABELS if p[k] == confidence]
            ties += len(winners) > 1
            predicted = winners[0]
            matrix[r['label']][predicted] += 1
            brier += sum((p[k] - int(r['label'] == k)) ** 2 for k in LABELS)
            bucket = bins[min(9, int(confidence * 10))]
            bucket['count'] += 1; bucket['confidence_sum'] += confidence; bucket['correct'] += predicted == r['label']
        per_class = {}
        for k in LABELS:
            actual = sum(matrix[k].values()); predicted = sum(matrix[a][k] for a in LABELS)
            per_class[k] = {'support': actual, 'precision': matrix[k][k] / predicted if predicted else None, 'recall': matrix[k][k] / actual if actual else None}
        human = per_class['human']['support']
        calibration = [{'lower': i / 10, 'upper': (i + 1) / 10, 'count': b['count'], 'mean_confidence': b['confidence_sum']/b['count'] if b['count'] else None, 'accuracy': b['correct']/b['count'] if b['count'] else None} for i,b in enumerate(bins)]
        return {'n': len(samples), 'confusion_actual_rows': matrix, 'per_class': per_class, 'human_flagged_ai_rate': matrix['human']['ai']/human if human else None, 'multiclass_brier': brier/len(samples), 'top_label_calibration': calibration, 'ties': ties}
    slices = {}
    for dimension in ('language', 'domain'):
        grouped = defaultdict(list)
        for r in heldout: grouped[r[dimension]].append(r)
        slices[dimension] = {key: metrics(value) for key,value in sorted(grouped.items())}
    provider, model = next(iter(versions))
    return {'provider': provider, 'model': model, 'notice': 'Held-out supplied labels only. Dataset provenance and independence require external review. No deployment accuracy is inferred. Ties use human, mixed, ai order.', 'overall': metrics(heldout), 'slices': slices}


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('input', type=Path)
    parser.add_argument('--output', type=Path, required=True)
    args = parser.parse_args()
    try:
        rows = [json.loads(line) for line in args.input.read_text(encoding='utf-8').splitlines() if line.strip()]
        result = evaluate(rows)
    except (ValueError, KeyError, TypeError, OSError) as error:
        parser.error(str(error))
    args.output.write_text(json.dumps(result, ensure_ascii=False, indent=2, allow_nan=False)+'\n', encoding='utf-8')
