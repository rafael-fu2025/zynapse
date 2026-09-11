import { useState } from 'react';
import {
  ChevronLeft,
  ChevronRight,
  Loader2,
  Lock,
  Plus,
} from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Dialog } from '@/components/ui/dialog';
import { QueryErrorRow } from '@/components/QueryErrorState';
import {
  Sheet,
  SheetContent,
  SheetHeader,
  SheetTitle,
} from '@/components/ui/sheet';
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table';
import { useSessions } from '@/hooks/useCounselling';
import { fmtUtcToApp } from '@/utils/date';
import { OpenSessionDialog } from '../dialogs';
import { SessionWorkspace } from '../workspace';

interface SessionsTabProps {
  selectedId: number | null;
  onSelect: (id: number | null) => void;
}

export function SessionsTab({ selectedId, onSelect }: SessionsTabProps) {
  const [cursor, setCursor] = useState<string | null>(null);
  const [history, setHistory] = useState<Array<string | null>>([null]);
  const [openNewSession, setOpenNewSession] = useState(false);
  const [mobileSheetOpen, setMobileSheetOpen] = useState(false);

  const sessions = useSessions(cursor, 25);

  function nextPage() {
    if (sessions.data?.next !== null && sessions.data?.next !== undefined) {
      const n = sessions.data.next;
      setHistory((h) => [...h, n]);
      setCursor(n);
    }
  }

  function prevPage() {
    if (history.length < 2) return;
    const next = history.slice(0, -1);
    setHistory(next);
    setCursor(next[next.length - 1] ?? null);
  }

  function handleSelectSession(id: number) {
    onSelect(id);
    // On mobile / tablet (< lg), open the slide-over sheet drawer
    if (window.innerWidth < 1024) {
      setMobileSheetOpen(true);
    }
  }

  return (
    <div className="space-y-4">
      <div className="flex items-center justify-between">
        <div>
          <h2 className="text-sm font-semibold text-foreground">Session records</h2>
          <p className="text-xs text-muted-foreground">Encrypted clinical sessions and encrypted notes.</p>
        </div>
        <Button onClick={() => setOpenNewSession(true)}>
          <Plus className="size-4" /> Open session
        </Button>
      </div>

      {/* Responsive Master-Detail: 5/12 list + 7/12 workspace on desktop */}
      <section className="grid gap-5 lg:grid-cols-12">
        {/* Master List (5 cols on lg, 12 on mobile) */}
        <article className="flex flex-col overflow-hidden rounded-xl border bg-card lg:col-span-5">
          <header className="border-b px-4 py-3 text-sm font-semibold text-foreground">
            All Sessions
          </header>
          <Table>
            <TableHeader className="bg-muted/50">
              <TableRow>
                <TableHead className="px-3">#</TableHead>
                <TableHead className="px-3">Patient</TableHead>
                <TableHead className="px-3">Started</TableHead>
                <TableHead className="px-3">Status</TableHead>
                <TableHead className="px-3 text-right">Action</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {sessions.isLoading && (
                <TableRow>
                  <TableCell colSpan={5} className="px-3 py-8 text-center text-muted-foreground">
                    <Loader2 className="mx-auto size-5 animate-spin" />
                  </TableCell>
                </TableRow>
              )}
              {!sessions.isLoading && (sessions.data?.data.length ?? 0) === 0 && (
                <TableRow>
                  <TableCell colSpan={5} className="px-3 py-8 text-center text-muted-foreground">
                    No sessions found.
                  </TableCell>
                </TableRow>
              )}
              {sessions.isError && !sessions.isLoading && (
                <QueryErrorRow
                  colSpan={5}
                  message="Failed to load sessions."
                  onRetry={() => void sessions.refetch()}
                  pending={sessions.isFetching}
                />
              )}
              {sessions.data?.data.map((s) => {
                const isSelected = selectedId === s.id;
                return (
                  <TableRow
                    key={s.id}
                    className={`cursor-pointer transition-colors ${isSelected ? 'bg-primary/10 font-medium' : ''}`}
                    onClick={() => handleSelectSession(s.id)}
                  >
                    <TableCell className="px-3 font-mono text-xs">{s.id}</TableCell>
                    <TableCell className="px-3 font-mono text-xs">{s.patient_school_id}</TableCell>
                    <TableCell className="px-3 text-xs text-muted-foreground">
                      {fmtUtcToApp(s.started_at)}
                    </TableCell>
                    <TableCell className="px-3 text-xs">
                      {s.ended_at === null ? (
                        <Badge variant="info">Active</Badge>
                      ) : (
                        <Badge variant="secondary">Closed</Badge>
                      )}
                    </TableCell>
                    <TableCell className="px-3 text-right">
                      <Button
                        size="sm"
                        variant={isSelected ? 'default' : 'outline'}
                        className="h-7 text-xs"
                        onClick={(ev) => {
                          ev.stopPropagation();
                          handleSelectSession(s.id);
                        }}
                      >
                        {s.ended_at === null ? 'Active' : 'View'}
                      </Button>
                    </TableCell>
                  </TableRow>
                );
              })}
            </TableBody>
          </Table>

          <nav className="mt-auto flex items-center justify-between border-t px-4 py-3">
            <Button
              size="sm"
              variant="outline"
              onClick={prevPage}
              disabled={history.length < 2}
            >
              <ChevronLeft className="size-4" /> Previous
            </Button>
            <Button
              size="sm"
              variant="outline"
              onClick={nextPage}
              disabled={sessions.data?.next === null || sessions.data?.next === undefined}
            >
              Next <ChevronRight className="size-4" />
            </Button>
          </nav>
        </article>

        {/* Desktop Detail Workspace (7 cols on lg, hidden on mobile) */}
        <div className="hidden lg:col-span-7 lg:block">
          {selectedId !== null ? (
            <SessionWorkspace
              sessionId={selectedId}
              onCloseWorkspace={() => onSelect(null)}
            />
          ) : (
            <div className="flex h-96 flex-col items-center justify-center rounded-xl border border-dashed p-8 text-center text-muted-foreground">
              <Lock className="mb-2 size-8 opacity-40" />
              <p className="font-medium">No session selected</p>
              <p className="mt-1 max-w-sm text-xs">
                Select a session from the list on the left to view notes, patient details, and manage clinical progress.
              </p>
            </div>
          )}
        </div>
      </section>

      {/* Mobile Drawer (Sheet) for small screens */}
      <Sheet open={mobileSheetOpen} onOpenChange={setMobileSheetOpen}>
        <SheetContent side="right" className="w-full sm:max-w-xl overflow-y-auto p-4">
          <SheetHeader className="mb-3 text-left">
            <SheetTitle>Session Workspace</SheetTitle>
          </SheetHeader>
          {selectedId !== null && (
            <SessionWorkspace
              sessionId={selectedId}
              onCloseWorkspace={() => {
                setMobileSheetOpen(false);
                onSelect(null);
              }}
            />
          )}
        </SheetContent>
      </Sheet>

      <Dialog open={openNewSession} onOpenChange={setOpenNewSession}>
        {openNewSession && <OpenSessionDialog onClose={() => setOpenNewSession(false)} />}
      </Dialog>
    </div>
  );
}
