import { Loader2, Syringe } from 'lucide-react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import {
  DialogContent,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { ClinicCatalogPicker } from '@/components/clinic/ClinicCatalogPicker';
import { useMoveStock } from '@/hooks/useInventory';
import type { InventoryItem } from '@/schemas/inventory';

/**
 * DispenseSupplyDialog — supply-side twin of the medicine dispense:
 * a positive quantity is turned into a negative `dispense` movement on
 * the ledger. Like medicines, the dispense is anchored to an OPEN
 * encounter so the ledger records which visit consumed the stock
 * (inventory audit fix).
 */
export function DispenseSupplyDialog({ item, onClose }: { item: InventoryItem; onClose: () => void }) {
  const move = useMoveStock();
  const [qty, setQty] = useState('');
  const [encounterId, setEncounterId] = useState('');
  const [note, setNote] = useState('');

  const parsed = Number(qty);
  const valid = Number.isInteger(parsed) && parsed >= 1 && parsed <= item.quantity_on_hand;
  const encId = encounterId === '' ? Number.NaN : Number(encounterId);
  const hasEncounter = Number.isInteger(encId) && encId > 0;

  return (
    <DialogContent lockDismiss>
      <DialogHeader>
        <DialogTitle>Dispense — {item.name}</DialogTitle>
      </DialogHeader>
      <div className="space-y-3">
        <p className="text-xs text-muted-foreground">
          On hand: <span className="tabular-nums">{item.quantity_on_hand} {item.unit}</span>. Stock is tied to
          an open clinic visit.
        </p>
        <div className="space-y-1.5">
          <Label htmlFor="dispense_supply_encounter">Open encounter</Label>
          <ClinicCatalogPicker kind="encounter" id="dispense_supply_encounter" value={encounterId} onChange={setEncounterId} />

        </div>
        <div className="space-y-1.5">
          <Label htmlFor="dispense_supply_qty">Quantity</Label>
          <Input
            id="dispense_supply_qty"
            type="number"
            min={1}
            max={item.quantity_on_hand}
            value={qty}
            aria-invalid={qty !== '' && !valid}
            onChange={(e) => setQty(e.target.value)}
          />
          {qty !== '' && !valid && (
            <p role="alert" className="text-xs text-destructive">Enter 1–{item.quantity_on_hand}.</p>
          )}
        </div>
        <div className="space-y-1.5">
          <Label htmlFor="dispense_supply_note">Note (optional)</Label>
          <Input id="dispense_supply_note" value={note} onChange={(e) => setNote(e.target.value)} maxLength={255} />
        </div>
      </div>
      <DialogFooter>
        <Button variant="outline" onClick={onClose}>Cancel</Button>
        <Button
          disabled={!valid || !hasEncounter || move.isPending}
          onClick={() => move.mutate(
            {
              itemId: item.id,
              input: {
                qty_delta: -parsed,
                reason_code: 'dispense',
                encounter_id: encId,
                ...(note !== '' ? { note } : {}),
              },
            },
            { onSuccess: onClose },
          )}
        >
          {move.isPending && <Loader2 className="animate-spin" />}
          <Syringe /> Dispense
        </Button>
      </DialogFooter>
    </DialogContent>
  );
}
