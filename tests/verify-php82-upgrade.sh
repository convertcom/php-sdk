#!/usr/bin/env bash
# Verification script for Story 1.1: PHP 8.2 Upgrade & Strict Types
# Validates all acceptance criteria are met.
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$REPO_ROOT"

FAIL=0
pass() { echo "  ✅ $1"; }
fail() { echo "  ❌ $1"; FAIL=1; }

echo "=== AC #1 & #5: PHP ^8.2 in all composer.json ==="
for f in composer.json packages/*/composer.json; do
  if grep -q '"php": "\^8\.2"' "$f" 2>/dev/null; then
    pass "$f"
  else
    fail "$f — missing or incorrect php constraint"
  fi
done

echo ""
echo "=== AC #2: declare(strict_types=1) in all PHP files (excl Types/lib) ==="
MISSING=$(find packages -name "*.php" \( -path "*/src/*" -o -path "*/tests/*" \) ! -path "*/Types/lib/*" -exec grep -L 'declare(strict_types=1)' {} \;)
if [ -z "$MISSING" ]; then
  pass "All PHP files have declare(strict_types=1)"
else
  fail "Files missing strict_types:"
  echo "$MISSING"
fi

echo ""
echo "=== AC #2 (format): Blank line between declare and namespace ==="
BAD_FORMAT=0
for f in $(find packages -name "*.php" \( -path "*/src/*" -o -path "*/tests/*" \) ! -path "*/Types/lib/*"); do
  if head -6 "$f" | tr '\n' '|' | grep -q 'declare(strict_types=1);|namespace\|declare(strict_types=1);|use \|declare(strict_types=1);|//' 2>/dev/null; then
    fail "$f — missing blank line after declare(strict_types=1)"
    BAD_FORMAT=1
  fi
done
if [ "$BAD_FORMAT" -eq 0 ]; then
  pass "All files have correct declare formatting"
fi

echo ""
echo "=== AC #3: No ext-swoole references ==="
SWOOLE=$(grep -rl "ext-swoole" packages/*/composer.json composer.json 2>/dev/null || true)
if [ -z "$SWOOLE" ]; then
  pass "No ext-swoole references found"
else
  fail "ext-swoole found in: $SWOOLE"
fi

echo ""
echo "=== AC #4: Namespace conventions ==="
BAD_NS=$(grep -rh "^namespace " packages/*/src/ --include="*.php" 2>/dev/null | grep -v "ConvertSdk" || true)
if [ -z "$BAD_NS" ]; then
  pass "All non-Types packages use ConvertSdk\\ namespace"
else
  fail "Non-ConvertSdk namespaces found: $BAD_NS"
fi

TYPES_NS=$(grep -rh "^namespace " packages/Types/lib/ --include="*.php" 2>/dev/null | grep -v "OpenAPI\|OpenApi" || true)
if [ -z "$TYPES_NS" ]; then
  pass "Types package uses OpenAPI\\Client\\ namespace"
else
  fail "Types package has non-OpenAPI namespaces: $TYPES_NS"
fi

echo ""
echo "=== AC #2 (exclusion): Types/lib files NOT modified ==="
TYPES_STRICT=$(grep -rl 'declare(strict_types=1)' packages/Types/lib/ 2>/dev/null || true)
if [ -z "$TYPES_STRICT" ]; then
  pass "Types/lib files untouched"
else
  fail "Types/lib files have strict_types: $TYPES_STRICT"
fi

echo ""
if [ "$FAIL" -eq 0 ]; then
  echo "🎉 ALL ACCEPTANCE CRITERIA PASS"
  exit 0
else
  echo "💥 SOME CHECKS FAILED — see above"
  exit 1
fi
