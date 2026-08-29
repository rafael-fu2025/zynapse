// Curated starter values for catalogue fields — mirrors the SPA's
// `frontend/src/data/taxonomy.ts`. The stored value is the lowercase token;
// `label` is the friendlier display text shown in the searchable picker.
// Fields still accept create-on-the-fly values (values hit the API as plain
// strings, so the server-side catalogue converges over time).

class TaxonomyEntry {
  const TaxonomyEntry(this.value, [this.label]);

  final String value;
  final String? label;
}

/// Common clinic-internal pharmacological categories (WHO ATC simplified).
const medicineCategories = <TaxonomyEntry>[
  TaxonomyEntry('analgesic', 'Analgesic (pain relief)'),
  TaxonomyEntry('antacid'),
  TaxonomyEntry('antiallergic', 'Anti-allergic'),
  TaxonomyEntry('antiasthmatic'),
  TaxonomyEntry('antibiotic'),
  TaxonomyEntry('anticoagulant'),
  TaxonomyEntry('antidepressant'),
  TaxonomyEntry('antidiabetic'),
  TaxonomyEntry('antidiarrheal'),
  TaxonomyEntry('antiemetic'),
  TaxonomyEntry('antiepileptic', 'Anti-epileptic'),
  TaxonomyEntry('antifungal'),
  TaxonomyEntry('antihelmintic', 'Dewormer (anthelmintic)'),
  TaxonomyEntry('antihypertensive'),
  TaxonomyEntry('anti-inflammatory'),
  TaxonomyEntry('antimalarial'),
  TaxonomyEntry('antimigraine'),
  TaxonomyEntry('antipsychotic'),
  TaxonomyEntry('antipyretic', 'Antipyretic (fever reducer)'),
  TaxonomyEntry('antiseptic'),
  TaxonomyEntry('antitussive', 'Cough suppressant'),
  TaxonomyEntry('antiviral'),
  TaxonomyEntry('anxiolytic', 'Anxiolytic (anxiety)'),
  TaxonomyEntry('bronchodilator'),
  TaxonomyEntry('cardiac'),
  TaxonomyEntry('contraceptive'),
  TaxonomyEntry('corticosteroid'),
  TaxonomyEntry('dermatologic'),
  TaxonomyEntry('decongestant'),
  TaxonomyEntry('diuretic'),
  TaxonomyEntry('electrolyte'),
  TaxonomyEntry('expectorant'),
  TaxonomyEntry('gastrointestinal'),
  TaxonomyEntry('hormonal'),
  TaxonomyEntry('hypnotic', 'Sleep aid (hypnotic)'),
  TaxonomyEntry('laxative'),
  TaxonomyEntry('mineral'),
  TaxonomyEntry('mucolytic'),
  TaxonomyEntry('muscle-relaxant'),
  TaxonomyEntry('ophthalmic', 'Eye/ear drop (ophthalmic/otic)'),
  TaxonomyEntry('sedative'),
  TaxonomyEntry('supplement'),
  TaxonomyEntry('topical'),
  TaxonomyEntry('vitamin'),
  TaxonomyEntry('other', 'Other (specify in description)'),
];

/// Dosage forms — how the dose is packaged.
const medicineDosageForms = <TaxonomyEntry>[
  TaxonomyEntry('tablet'),
  TaxonomyEntry('capsule'),
  TaxonomyEntry('softgel'),
  TaxonomyEntry('caplet'),
  TaxonomyEntry('syrup'),
  TaxonomyEntry('suspension'),
  TaxonomyEntry('elixir'),
  TaxonomyEntry('solution'),
  TaxonomyEntry('drops'),
  TaxonomyEntry('injection', 'Injection (IM/IV/SC)'),
  TaxonomyEntry('ointment'),
  TaxonomyEntry('cream'),
  TaxonomyEntry('gel'),
  TaxonomyEntry('lotion'),
  TaxonomyEntry('patch'),
  TaxonomyEntry('suppository'),
  TaxonomyEntry('lozenge'),
  TaxonomyEntry('inhaler'),
  TaxonomyEntry('nebulizer'),
  TaxonomyEntry('sachet'),
  TaxonomyEntry('granules'),
];

/// Units of measure for medicine bottles / blister packs.
const medicineUnits = <TaxonomyEntry>[
  TaxonomyEntry('tab', 'tab · tablet'),
  TaxonomyEntry('cap', 'cap · capsule'),
  TaxonomyEntry('sachet'),
  TaxonomyEntry('mL'),
  TaxonomyEntry('L'),
  TaxonomyEntry('mg'),
  TaxonomyEntry('g'),
  TaxonomyEntry('mcg'),
  TaxonomyEntry('IU'),
  TaxonomyEntry('vial'),
  TaxonomyEntry('amp', 'ampule'),
  TaxonomyEntry('bottle'),
  TaxonomyEntry('tube'),
  TaxonomyEntry('strip'),
  TaxonomyEntry('blister'),
  TaxonomyEntry('box'),
  TaxonomyEntry('patch'),
  TaxonomyEntry('dose'),
  TaxonomyEntry('puff'),
  TaxonomyEntry('drop'),
  TaxonomyEntry('pc', 'pc · piece'),
];

/// Units for the simple supply ledger (heavier on physical packaging).
const inventoryUnits = <TaxonomyEntry>[
  TaxonomyEntry('pc', 'pc · piece'),
  TaxonomyEntry('pair'),
  TaxonomyEntry('set'),
  TaxonomyEntry('box'),
  TaxonomyEntry('pack'),
  TaxonomyEntry('roll'),
  TaxonomyEntry('sheet'),
  TaxonomyEntry('m'),
  TaxonomyEntry('cm'),
  TaxonomyEntry('mm'),
  TaxonomyEntry('L'),
  TaxonomyEntry('mL'),
  TaxonomyEntry('kg'),
  TaxonomyEntry('g'),
  TaxonomyEntry('bottle'),
  TaxonomyEntry('tube'),
  TaxonomyEntry('can'),
  TaxonomyEntry('pouch'),
  TaxonomyEntry('sachet'),
];
