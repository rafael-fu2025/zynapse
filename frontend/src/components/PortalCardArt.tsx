/**
 * PortalCardArt — shared decorative layers for the fixed ATM-style portal
 * profile cards: the white levin7 fade mark anchored to the right edge
 * (vertically centered, 70% of card height) — over the maroon card face
 * in light mode and the dark card face in dark mode — plus a
 * fractal-noise grain washed over the surface for a premium card feel.
 * Purely decorative — pointer-transparent and hidden from the a11y tree.
 */
export function PortalCardArt() {
  return (
    <>
      <img
        src="/levin7-white-fade.svg"
        alt=""
        aria-hidden="true"
        draggable={false}
        className="pointer-events-none absolute right-0 top-1/2 h-[70%] w-auto -translate-y-1/2 select-none"
      />
      <div
        aria-hidden="true"
        className="pointer-events-none absolute inset-0 opacity-[0.05] mix-blend-overlay"
        style={{
          backgroundImage:
            "url(\"data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='160' height='160'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.9' numOctaves='2' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23n)'/%3E%3C/svg%3E\")",
        }}
      />
    </>
  );
}
