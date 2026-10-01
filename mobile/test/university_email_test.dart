import 'package:flutter_test/flutter_test.dart';

import 'package:synapse_mobile/core/utils/university_email.dart';

/// Mirrors the web's `frontend/src/lib/universityEmail.test.ts` case for
/// case — the two implementations must never derive differently.
void main() {
  group('deriveUniversityEmail', () {
    test('concatenates multi-word given names and the surname', () {
      expect(deriveUniversityEmail('John Lloyd', 'Macias'),
          'johnlloyd.macias@foundationu.com');
    });

    test('folds case and concatenates multi-word surnames', () {
      expect(deriveUniversityEmail('JUAN', 'DELA CRUZ'),
          'juan.delacruz@foundationu.com');
    });

    test('strips generational suffixes from the surname', () {
      expect(deriveUniversityEmail('Jose', 'Dela Cruz Jr.'),
          'jose.delacruz@foundationu.com');
      expect(deriveUniversityEmail('Ana', 'Santos III'),
          'ana.santos@foundationu.com');
    });

    test('folds PH diacritics to ASCII letters', () {
      expect(
          deriveUniversityEmail('María', 'Ñuñez'), 'maria.nunez@foundationu.com');
    });

    test('drops non-alphabetic characters inside names', () {
      expect(deriveUniversityEmail('Ma. Joy-Anne', "O'Neil"),
          'majoyanne.oneil@foundationu.com');
    });

    test('returns null when either side yields no letters', () {
      expect(deriveUniversityEmail('', 'Macias'), isNull);
      expect(deriveUniversityEmail('John', ''), isNull);
      expect(deriveUniversityEmail('123', 'Macias'), isNull);
      expect(deriveUniversityEmail(null, null), isNull);
    });

    test('keeps a suffix-only surname from collapsing to nothing', () {
      // "Jr" alone strips to empty — no honest address to derive.
      expect(deriveUniversityEmail('Jose', 'Jr'), isNull);
    });
  });
}
