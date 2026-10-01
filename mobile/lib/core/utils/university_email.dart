/// Derive the Foundation University email from a person's name — Dart port
/// of `frontend/src/lib/universityEmail.ts` (same convention as the
/// backend's `App\Services\UniversityEmail`, which serves MIS-provisioned
/// users): given names concatenated, then a dot, then the surname
/// concatenated, then `@foundationu.com`.
///
///   "John Lloyd" + "Macias"  -> johnlloyd.macias@foundationu.com
///   "JUAN" + "DELA CRUZ"     -> juan.delacruz@foundationu.com
///
/// Rules fixed by the university's account convention:
///   - middle names are never part of the address — callers pass
///     first/last only;
///   - every non-alphabetic character is dropped (spaces, hyphens,
///     apostrophes, dots);
///   - common Spanish/PH diacritics fold to their lowercase ASCII letter
///     (ñ -> n);
///   - generational suffixes (JR, SR, II..V) are stripped from the surname;
///   - either side empty -> null (no honest address to derive).
library;

const _domain = '@foundationu.com';

const _suffixes = {'jr', 'sr', 'ii', 'iii', 'iv', 'v'};

const _transliterations = <String, String>{
  'ñ': 'n',
  'á': 'a',
  'à': 'a',
  'â': 'a',
  'ä': 'a',
  'é': 'e',
  'è': 'e',
  'ê': 'e',
  'ë': 'e',
  'í': 'i',
  'ì': 'i',
  'î': 'i',
  'ï': 'i',
  'ó': 'o',
  'ò': 'o',
  'ô': 'o',
  'ö': 'o',
  'ú': 'u',
  'ù': 'u',
  'û': 'u',
  'ü': 'u',
  'ç': 'c',
};

final _diacritics = RegExp('[ñáàâäéèêëíìîïóòôöúùûüç]');
final _nonLowerAscii = RegExp('[^a-z]');

/// Lowercase, fold diacritics, then concatenate the a-z letters.
String _lettersOnly(String value) {
  final lowered = value.trim().toLowerCase().replaceAllMapped(
        _diacritics,
        (m) => _transliterations[m[0]] ?? m[0]!,
      );
  return lowered.replaceAll(_nonLowerAscii, '');
}

/// Drop trailing generational suffix tokens ("Macias Jr" -> "Macias").
String _stripSuffix(String lastName) {
  final parts = lastName
      .trim()
      .split(RegExp(r'\s+'))
      .where((p) => p.isNotEmpty)
      .toList();
  while (parts.isNotEmpty) {
    final token = parts.last.toLowerCase().replaceFirst(RegExp(r'\.$'), '');
    if (!_suffixes.contains(token) || token.isEmpty) break;
    parts.removeLast();
  }
  return parts.join(' ');
}

/// The `first.last@foundationu.com` address, or null when either side
/// yields no letters.
String? deriveUniversityEmail(String? firstName, String? lastName) {
  final first = _lettersOnly(firstName ?? '');
  final last = _lettersOnly(_stripSuffix(lastName ?? ''));
  if (first.isEmpty || last.isEmpty) {
    return null;
  }
  return '$first.$last$_domain';
}
