import { useMemo, useState } from 'react';
import { ComboboxField } from '@/components/ComboboxField';
import { Button } from '@/components/ui/button';
import { useDebouncedValue } from '@/hooks/useDebouncedValue';
import { clinicCatalogOption, useClinicCatalog, type ClinicCatalogKind, type ClinicCatalogRow } from '@/hooks/useClinicCatalog';

export function ClinicCatalogPicker({ kind, id, value, onChange, invalid = false }: {
  kind: Exclude<ClinicCatalogKind, 'equipment'>;
  id: string;
  value: string;
  onChange: (id: string, row?: ClinicCatalogRow) => void;
  invalid?: boolean;
}) {
  const [search, setSearch] = useState('');
  const q = useDebouncedValue(search.trim(), 250);
  const catalog = useClinicCatalog(kind, q);
  const [selected, setSelected] = useState<ClinicCatalogRow | null>(null);
  const rows = useMemo(() => {
    const unique = new Map<number, ClinicCatalogRow>();
    for (const page of catalog.data?.pages ?? []) for (const row of page.data) unique.set(row.id, row);
    if (selected !== null && String(selected.id) === value && !unique.has(selected.id)) unique.set(selected.id, selected);
    return [...unique.values()];
  }, [catalog.data, selected, value]);
  const controls = <>    {catalog.isError ? <div role="alert" className="text-xs text-destructive">
      Could not load options. <Button type="button" size="sm" variant="outline" disabled={catalog.isFetching}
        onClick={() => void (catalog.isFetchNextPageError ? catalog.fetchNextPage() : catalog.refetch())}>Retry options</Button>
    </div> : null}
    {catalog.hasNextPage && <div className="flex flex-wrap items-center gap-2">
      <p role="status" className="text-xs text-muted-foreground">
        {kind === 'encounter' ? 'Search covers loaded open visits only.' : 'More matches available.'} Load more or refine your search.
      </p>
      <Button type="button" variant="outline" size="sm" disabled={catalog.isFetching || search.trim() !== q}
        onClick={() => void catalog.fetchNextPage()}>Load more options</Button>
    </div>}
    {catalog.isFetching && <p role="status" className="text-xs text-muted-foreground">Loading options…</p>}
  </>;
  return <div className="space-y-1.5">
    <ComboboxField
      id={id} sourceKey={`clinic.catalog.${kind}`} value={value} normalize={false}
      lookupFooter={controls} invalid={invalid} options={rows.map(clinicCatalogOption)} onSearchChange={setSearch}
      placeholder={kind === 'encounter' ? 'Search loaded visits…' : 'Search catalogue…'}
      emptyHintLabel={catalog.isLoading ? 'Loading options…' : catalog.isError ? 'Options unavailable.' : 'No loaded matches.'}
      onChange={(next) => {
        const row = rows.find((item) => String(item.id) === next);
        setSelected(row ?? null);
        onChange(next, row);
      }}
    />
    {controls}
  </div>;
}
