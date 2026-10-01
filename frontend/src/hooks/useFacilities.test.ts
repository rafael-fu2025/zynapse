import { describe, expect, it } from 'vitest';

import { buildProcessLogPayload } from './useFacilities';

/**
 * Wire-payload construction for the process log.
 *
 * `event_type` is the field that makes a log a TURNING record. The
 * backend derives `days_since_last_turning` from it, which is in turn
 * what raises the TURNING_DUE alert and what the drum card's "Last
 * turned" readout shows. It was once dropped by the payload builder
 * without any test noticing, so the entire turning-cadence feature was
 * dead for human operators (only the ESP32 path worked, because the
 * device controller forces the value server-side).
 */
describe('buildProcessLogPayload', () => {
  it('carries event_type through to the wire', () => {
    expect(buildProcessLogPayload({ event_type: 'turning' })).toEqual({
      event_type: 'turning',
    });
  });

  it('carries every process event type, not just turning', () => {
    for (const type of ['observation', 'aeration', 'moisture_adjustment', 'other'] as const) {
      expect(buildProcessLogPayload({ event_type: type })['event_type']).toBe(type);
    }
  });

  it('carries the full sensor and note set together', () => {
    expect(
      buildProcessLogPayload({
        event_type: 'turning',
        observation_note: 'third pass this week',
        temperature_celsius: '58.5',
        moisture_level: 'normal',
        oxygen_pct: '9.2',
        device_id: 'b8-1f-3f-d7-ec-18',
        calibration_status: 'ok',
      }),
    ).toEqual({
      event_type: 'turning',
      observation_note: 'third pass this week',
      temperature_celsius: '58.5',
      moisture_level: 'normal',
      oxygen_pct: '9.2',
      device_id: 'b8-1f-3f-d7-ec-18',
      calibration_status: 'ok',
    });
  });

  it('omits event_type entirely when the operator did not choose one', () => {
    // The server defaults to 'observation'; sending an empty string
    // instead of omitting the key would fail the in_list validation.
    const payload = buildProcessLogPayload({ observation_note: 'looked fine' });
    expect(payload).not.toHaveProperty('event_type');
    expect(payload).toEqual({ observation_note: 'looked fine' });
  });

  it('strips empty optionals so the server sees nulls, not empty strings', () => {
    const payload = buildProcessLogPayload({
      observation_note: '',
      temperature_celsius: '',
      oxygen_pct: '',
      device_id: '',
    });
    expect(payload).toEqual({});
  });

  it('rejects a non-numeric reading before it reaches the wire', () => {
    // Range enforcement is the server's job (the controller caps
    // temperature at [-20, 120]); the client's job is to reject
    // something that is not a number at all.
    expect(() => buildProcessLogPayload({ temperature_celsius: 'warm' })).toThrow();
    expect(() => buildProcessLogPayload({ oxygen_pct: 'n/a' })).toThrow();
  });

  it('rejects an unknown event type', () => {
    expect(() =>
      buildProcessLogPayload({ event_type: 'sprinkled' as never }),
    ).toThrow();
  });
});
