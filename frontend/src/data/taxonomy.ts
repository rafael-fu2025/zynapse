/**
 * Taxonomy — curated starter lists for free-text catalogue fields.
 *
 * Goal: stop duplicate rows caused by "Paracetamol" vs "Paracetemol"
 * vs "PARACETAMOL" (or "tab" vs "tablet" vs "tabs"), without locking
 * the catalogue to a fixed enum — every field still accepts free
 * input via `ComboboxField`'s create-on-the-fly path.
 *
 * The lists are intentionally small, common, and bias toward what a
 * Philippines school clinic / counselling office + BMG facility
 * actually stocks. They grow at runtime via a Zustand store when the
 * operator picks "Add new <value>"; both the in-memory cache and
 * later-ranked suggestions stay consistent across reloads inside a
 * tab session (session-only is enough — values hit the API as plain
 * strings, so the server-side catalogue converges over time anyway).
 *
 * Maintainers: add rows in alphabetical order; the combobox uses the
 * raw order to rank, with `label` overrides for any localized term.
 */

export interface TaxonomyEntry {
  /** Stored value (lowercase, no trailing whitespace). */
  value: string;
  /** Shown in suggestions; falls back to `value` when omitted. */
  label?: string;
  /**
   * Optional secondary line shown under the label (e.g. "Paracetamol ·
   * 500mg · tablet · (Biogesic) — 120 on hand"). Renders as a dim
   * second line in the dropdown row; not stored, never serialized.
   */
  hint?: string;
}

// ---------------------------------------------------------- medicines

/**
 * Common clinic-internal pharmacological categories. The classic
 * WHO ATC sections, simplified for stock-keeping.
 */
export const MEDICINE_CATEGORIES: ReadonlyArray<TaxonomyEntry> = [
  { value: 'analgesic', label: 'Analgesic (pain relief)' },
  { value: 'antacid' },
  { value: 'antiallergic', label: 'Anti-allergic' },
  { value: 'antiasthmatic' },
  { value: 'antibiotic' },
  { value: 'anticoagulant' },
  { value: 'antidepressant' },
  { value: 'antidiabetic' },
  { value: 'antidiarrheal' },
  { value: 'antiemetic' },
  { value: 'antiepileptic', label: 'Anti-epileptic' },
  { value: 'antifungal' },
  { value: 'antihelmintic', label: 'Dewormer (anthelmintic)' },
  { value: 'antihypertensive' },
  { value: 'anti-inflammatory' },
  { value: 'antimalarial' },
  { value: 'antimigraine' },
  { value: 'antipsychotic' },
  { value: 'antipyretic', label: 'Antipyretic (fever reducer)' },
  { value: 'antiseptic' },
  { value: 'antitussive', label: 'Cough suppressant' },
  { value: 'antiviral' },
  { value: 'anxiolytic', label: 'Anxiolytic (anxiety)' },
  { value: 'bronchodilator' },
  { value: 'cardiac' },
  { value: 'contraceptive' },
  { value: 'corticosteroid' },
  { value: 'dermatologic' },
  { value: 'decongestant' },
  { value: 'diuretic' },
  { value: 'electrolyte' },
  { value: 'expectorant' },
  { value: 'gastrointestinal' },
  { value: 'hormonal' },
  { value: 'hypnotic', label: 'Sleep aid (hypnotic)' },
  { value: 'laxative' },
  { value: 'mineral' },
  { value: 'mucolytic' },
  { value: 'muscle-relaxant' },
  { value: 'ophthalmic', label: 'Eye/ear drop (ophthalmic/otic)' },
  { value: 'sedative' },
  { value: 'supplement' },
  { value: 'topical' },
  { value: 'vitamin' },
  { value: 'other', label: 'Other (specify in description)' },
];

/**
 * Dosage forms (the way the dose is packaged). UI renders the label
 * (e.g. "Tablet") but stores the lowercase token (e.g. "tablet") so
 * filters/search stay consistent.
 */
export const MEDICINE_DOSAGE_FORMS: ReadonlyArray<TaxonomyEntry> = [
  { value: 'tablet' },
  { value: 'capsule' },
  { value: 'softgel' },
  { value: 'caplet' },
  { value: 'syrup' },
  { value: 'suspension' },
  { value: 'elixir' },
  { value: 'solution' },
  { value: 'drops' },
  { value: 'injection', label: 'Injection (IM/IV/SC)' },
  { value: 'ointment' },
  { value: 'cream' },
  { value: 'gel' },
  { value: 'lotion' },
  { value: 'patch' },
  { value: 'suppository' },
  { value: 'lozenge' },
  { value: 'inhaler' },
  { value: 'nebulizer' },
  { value: 'sachet' },
  { value: 'granules' },
];

// ---------------------------------------------------------- units

/**
 * Units-of-measure used on medicine bottles / blister packs. Tokens
 * are short by convention; labels add the spelled-out form.
 */
export const MEDICINE_UNITS: ReadonlyArray<TaxonomyEntry> = [
  { value: 'tab', label: 'tab · tablet' },
  { value: 'cap', label: 'cap · capsule' },
  { value: 'sachet' },
  { value: 'mL' },
  { value: 'L' },
  { value: 'mg' },
  { value: 'g' },
  { value: 'mcg' },
  { value: 'IU' },
  { value: 'vial' },
  { value: 'amp', label: 'ampule' },
  { value: 'bottle' },
  { value: 'tube' },
  { value: 'strip' },
  { value: 'blister' },
  { value: 'box' },
  { value: 'patch' },
  { value: 'dose' },
  { value: 'puff' },
  { value: 'drop' },
  { value: 'pc', label: 'pc · piece' },
];

/**
 * Units for the simple supply ledger. Heavier on physical packaging
 * vs. pharmaceutical dosing.
 */
export const INVENTORY_UNITS: ReadonlyArray<TaxonomyEntry> = [
  { value: 'pc', label: 'pc · piece' },
  { value: 'pair' },
  { value: 'set' },
  { value: 'box' },
  { value: 'pack' },
  { value: 'roll' },
  { value: 'sheet' },
  { value: 'm' },
  { value: 'cm' },
  { value: 'mm' },
  { value: 'L' },
  { value: 'mL' },
  { value: 'kg' },
  { value: 'g' },
  { value: 'bottle' },
  { value: 'tube' },
  { value: 'can' },
  { value: 'pouch' },
  { value: 'sachet' },
];

// ---------------------------------------------------------- strengths

/**
 * Common medicine strengths (FDA/EMA/PH-standard shapes). The
 * operator can still free-type anything (`allowCreate` is on at the
 * call site) — this list just removes the "every clerk types
 * 500mg a slightly different way" drift.
 *
 * Shapes represented (a single product uses ONE shape):
 *   - Mass only           : 5mg, 100mg, 500mg, 1g          (tabs/caps)
 *   - Sub-milligram mass  : 100mcg                         (hormones, inhaler puffs)
 *   - Liquid volume       : 5mL, 60mL, 120mL               (syrups, drops)
 *   - Mass concentration  : 125mg/5mL, 5mg/mL              (oral suspensions, injections)
 *   - Percent             : 0.5%, 1%, 2%                   (creams, ointments, lidocaine)
 *   - Activity units      : 100units/mL                    (insulin, heparin, biologics)
 *
 * Per FDA labeling guidance:
 *   - Use `units` (not `U` / `IU`) and `mcg` (not `μg`) — error-prone.
 *   - Use a leading zero before decimals (`0.5`, never `.5`).
 *   - No trailing zeros after decimals (`2.5`, never `2.50`).
 *   - For single-mL injectables: `5 mg/mL`, never `5 mg/1 mL`.
 *
 * The set is intentionally small enough to memorize; anything rarer
 * belongs in the create-on-the-fly path.
 */
export const MEDICINE_STRENGTHS: ReadonlyArray<TaxonomyEntry> = [
  // --- Mass (tablets / capsules) ---
  { value: '5mg' },
  { value: '10mg' },
  { value: '20mg' },
  { value: '25mg' },
  { value: '50mg' },
  { value: '75mg' },
  { value: '100mg' },
  { value: '150mg' },
  { value: '200mg' },
  { value: '250mg' },
  { value: '300mg' },
  { value: '400mg' },
  { value: '500mg' },
  { value: '850mg' },
  { value: '1g' },

  // --- Sub-milligram (hormones, inhaler puffs) ---
  { value: '100mcg' },
  { value: '200mcg' },
  { value: '400mcg' },

  // --- Liquid volume (syrups, drops) ---
  { value: '5mL' },
  { value: '10mL' },
  { value: '15mL' },
  { value: '30mL' },
  { value: '60mL' },
  { value: '120mL' },
  { value: '250mL' },

  // --- Mass concentration — oral suspensions (mg per 5 mL) ---
  { value: '100mg/5mL' },
  { value: '120mg/5mL' },
  { value: '125mg/5mL' },
  { value: '200mg/5mL' },
  { value: '250mg/5mL' },
  { value: '400mg/5mL' },

  // --- Mass concentration — injections (mg per 1 mL) ---
  { value: '1mg/mL' },
  { value: '2mg/mL' },
  { value: '5mg/mL' },
  { value: '10mg/mL' },
  { value: '25mg/mL' },
  { value: '40mg/mL' },
  { value: '50mg/mL' },
  { value: '100mg/mL' },

  // --- Topical percent (creams / ointments / lidocaine) ---
  { value: '0.25%' },
  { value: '0.5%' },
  { value: '1%' },
  { value: '2%' },
  { value: '5%' },

  // --- Activity units (insulin, heparin, biologics) ---
  { value: '40units/mL', label: '40 units/mL · insulin U-40' },
  { value: '100units/mL', label: '100 units/mL · insulin U-100' },
  { value: '500units/mL', label: '500 units/mL · insulin U-500' },
  { value: '1000units/mL', label: '1000 units/mL · heparin (subq)' },
  { value: '5000units/mL', label: '5000 units/mL · heparin (IV)' },
];

/**
 * In-place hint shown under the dosage_strength field — the FDA-canonical
 * shapes for each form class. The combobox and the create-on-the-fly path
 * both accept any of these.
 *
 * Format rules encoded here (so the operator doesn't have to memorize them):
 *   - Number + unit, no space in storage (compact inventory token)
 *   - `mcg` for sub-milligram (not `μg` / `ug`)
 *   - `units` for activity (not `U` / `IU`)
 *   - Leading zero before decimal (`0.5`, never `.5`)
 *   - `mg/mL` for single-mL injectables (no `1`)
 */
export const MEDICINE_STRENGTH_EXAMPLES: ReadonlyArray<string> = [
  '500mg',
  '5mg/mL',
  '100mg/5mL',
  '0.5%',
  '100units/mL',
];

/**
 * HTML5 `pattern` for the strength field — flags malformed create-on-the-fly
 * values on form submit via the browser's native validation tooltip.
 *
 * The browser wraps this in `^(?:...)$` implicitly, so we describe one
 * full-string shape: a number, a unit, and an optional `\d*unit` tail.
 *
 * Accepts:
 *   - mass            : 500mg, 1g, 100mcg
 *   - volume          : 60mL, 250mL
 *   - percent         : 0.5%, 1%
 *   - mass conc       : 5mg/mL, 100mg/5mL, 12.5mg/0.625mL
 *   - activity conc   : 100units/mL, 5000units/mL
 *
 * Rejects:
 *   - pure garbage    : xyz, &&%, asdf, 123 (no unit)
 *   - unit-less       : mcg500 (number not at start)
 *   - bare leading dot: .5% (FDA wants leading zero: 0.5%)
 *   - banned units    : 5u/mL (FDA wants `units`, not `U`)
 *
 * After ComboboxField's `normalizeTaxonomyValue` lower-cases the value, we
 * match against the lowercase token (`5mg/ml`, not `5mg/mL`) — both forms
 * still render correctly because the curated list has mixed-case labels.
 */
export const MEDICINE_STRENGTH_PATTERN =
  '\\d+\\.?\\d*\\s?(mg|mcg|g|ml|l|%|units)(\\s?/\\s?\\d*\\.?\\d*\\s?(ml|l|units))?';

// ---------------------------------------------------------- helpers

/** Case-insensitive substring match — partial if no exact hit exists. */
export function filterTaxonomy(
  source: ReadonlyArray<TaxonomyEntry>,
  query: string,
): TaxonomyEntry[] {
  const q = query.trim().toLowerCase();
  if (q === '') {
    // No filter — show the curated list in declared order.
    return [...source];
  }
  const exact: TaxonomyEntry[] = [];
  const prefix: TaxonomyEntry[] = [];
  const contains: TaxonomyEntry[] = [];
  for (const entry of source) {
    const haystackValue = entry.value.toLowerCase();
    const haystackLabel = (entry.label ?? '').toLowerCase();
    if (haystackValue === q || haystackLabel === q) {
      exact.push(entry);
    } else if (haystackValue.startsWith(q) || haystackLabel.startsWith(q)) {
      prefix.push(entry);
    } else if (haystackValue.includes(q) || haystackLabel.includes(q)) {
      contains.push(entry);
    }
  }
  return [...exact, ...prefix, ...contains];
}

/** Normalize a typed/picked value: trim, lowercase, collapse whitespace. */
export function normalizeTaxonomyValue(raw: string): string {
  return raw.trim().replace(/\s+/g, ' ').toLowerCase();
}
