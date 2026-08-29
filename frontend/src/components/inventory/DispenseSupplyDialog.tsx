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
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
import { useEncounters } from '@/hooks/useClinic';
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
  const encounters = useEncounters(null, 50, 'open');
  const [qty, setQty] = useState('');
  const [encounterId, setEncounterId] = useState('');
  const [note, setNote] = useState('');

  const parsed = Number(qty);
  const valid = Number.isInteger(parsed) && parsed >= 1 && parsed <= item.quantity_on_hand;
  const openEncounters = encounters.data?.data ?? [];
  const encId = encounterId === '' ? Number.NaN : Number(encounterId);
  const hasEncounter = Number.isInteger(encId) && encId > 0;

  return (
    <DialogContent>
      <DialogHeader>
        <DialogTitle>Dispense — {item.name}</DialogTitle>
      </DialogHeader>
      <div className="space-y-3">
        <p className="text-xs text-muted-foreground">
          On hand: <span className="font-mono">{item.quantity_on_hand} {item.unit}</span>. Stock is tied to
          an open clinic visit.
        </p>
        <div className="space-y-1.5">
          <Label id="dispense_supply_encounter-label">Open encounter</Label>
          <Select value={encounterId} onValueChange={setEncounterId}>
            <SelectTrigger aria-labelledby="dispense_supply_encounter-label" aria-invalid={!hasEncounter && encounterId !== ''}>
              <SelectValue placeholder={openEncounters.length === 0 ? 'No open encounters' : 'Select the visit…'} />
            </SelectTrigger>
            <SelectContent>
              {openEncounters.map((e) => (
                <SelectItem key={e.id} value={String(e.id)}>
                  #{e.id} · {e.patient_school_id} · {e.chief_complaint.slice(0, 40)}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
          {openEncounters.length === 0 && !encounters.isLoading && (
            <p className="text-[10px] text-muted-foreground">Open an encounter in Clinic first — dispensing must be tied to a visit.</p>
          )}
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
