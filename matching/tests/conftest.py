import sys
from pathlib import Path


MATCHING_ROOT = Path(__file__).resolve().parents[1]

if str(MATCHING_ROOT) not in sys.path:
    sys.path.insert(0, str(MATCHING_ROOT))
