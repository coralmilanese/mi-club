import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { NativeSelect } from '@/components/ui/native-select';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import { formatDate, formatMoney } from '@/lib/format';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { FormEventHandler, useState } from 'react';

interface Liq {
    id: number;
    fecha: string;
    base_credito: string;
    base_debito: string;
    ajustada: boolean;
    motivo: string | null;
    importes: Record<string, string>;
}
interface Props {
    cuentas: { id: number; nombre: string }[];
    cuenta_id: number | null;
    mes: string;
    tributos: { id: number; nombre: string; codigo: string }[];
    liquidaciones: Liq[];
    corte: string | null;
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Impuestos del día', href: '/libro-caja/liquidaciones' }];

function Ajuste({ liq, cuentaId, tributos, onCerrar }: { liq: Liq; cuentaId: number; tributos: Props['tributos']; onCerrar: () => void }) {
    const form = useForm({
        cuenta_id: cuentaId,
        fecha: liq.fecha,
        motivo: '',
        importes: Object.fromEntries(tributos.map((t) => [t.codigo, liq.importes[t.codigo] ?? '0.00'])) as Record<string, string>,
    });
    const enviar: FormEventHandler = (e) => {
        e.preventDefault();
        form.post(route('liquidaciones.ajustar'), { preserveScroll: true, onSuccess: onCerrar });
    };
    const errores = form.errors as Record<string, string>;

    return (
        <form onSubmit={enviar} className="bg-muted/40 grid gap-3 p-3 md:grid-cols-4">
            {tributos.map((t) => (
                <div key={t.codigo} className="grid gap-1">
                    <Label className="text-xs">{t.nombre}</Label>
                    <Input
                        type="number"
                        step="0.01"
                        min="0"
                        value={form.data.importes[t.codigo]}
                        onChange={(e) => form.setData('importes', { ...form.data.importes, [t.codigo]: e.target.value })}
                    />
                </div>
            ))}
            <div className="grid gap-1 md:col-span-3">
                <Label className="text-xs">Motivo del ajuste (obligatorio)</Label>
                <Input
                    value={form.data.motivo}
                    onChange={(e) => form.setData('motivo', e.target.value)}
                    placeholder="Ej.: el resumen bancario cobró un importe distinto"
                />
                <InputError message={errores.motivo} />
            </div>
            <div className="flex items-end gap-2">
                <Button type="submit" size="sm" disabled={form.processing || !form.data.motivo}>
                    Guardar ajuste
                </Button>
                <Button type="button" size="sm" variant="ghost" onClick={onCerrar}>
                    Cancelar
                </Button>
            </div>
        </form>
    );
}

export default function Liquidaciones({ cuentas, cuenta_id, mes, tributos, liquidaciones, corte }: Props) {
    const { auth } = usePage<SharedData>().props;
    const esTesorero = auth.user.rol === 'tesorero';
    const [editando, setEditando] = useState<number | null>(null);

    const ir = (cambios: Record<string, string | number>) =>
        router.get(route('liquidaciones.index'), { cuenta_id, mes, ...cambios }, { preserveState: true, replace: true });

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Impuestos del día" />
            <div className="flex flex-col gap-4 p-4">
                <h1 className="text-2xl font-semibold">Impuestos del día</h1>
                <p className="text-muted-foreground max-w-3xl text-sm">
                    SIRCREB e impuesto al crédito se calculan sobre lo acreditado en el día; el impuesto al débito, sobre lo debitado más el SIRCREB.
                    Se recalculan solos al cargar o anular cualquier movimiento.
                    {corte && <> Antes del {formatDate(corte)} mandan los importes cargados del Excel y no se reescriben.</>}
                </p>

                <div className="flex flex-wrap items-end gap-3">
                    <div className="grid gap-1">
                        <Label htmlFor="liq-cuenta">Cuenta</Label>
                        <NativeSelect id="liq-cuenta" className="w-52" value={cuenta_id ?? ''} onChange={(e) => ir({ cuenta_id: e.target.value })}>
                            {cuentas.map((c) => (
                                <option key={c.id} value={c.id}>
                                    {c.nombre}
                                </option>
                            ))}
                        </NativeSelect>
                    </div>
                    <div className="grid gap-1">
                        <Label htmlFor="liq-mes">Mes</Label>
                        <Input id="liq-mes" type="month" className="w-44" value={mes} onChange={(e) => ir({ mes: e.target.value })} />
                    </div>
                </div>

                <div className="rounded-md border">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Día</TableHead>
                                <TableHead className="text-right">Acreditado</TableHead>
                                <TableHead className="text-right">Debitado</TableHead>
                                {tributos.map((t) => (
                                    <TableHead key={t.codigo} className="text-right">
                                        {t.nombre}
                                    </TableHead>
                                ))}
                                <TableHead />
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {liquidaciones.map((l) => (
                                <>
                                    <TableRow key={l.id}>
                                        <TableCell>
                                            {formatDate(l.fecha)}
                                            {l.ajustada && (
                                                <Badge variant="secondary" className="ml-2" title={l.motivo ?? ''}>
                                                    ajustado a mano
                                                </Badge>
                                            )}
                                        </TableCell>
                                        <TableCell className="text-right">{formatMoney(l.base_credito)}</TableCell>
                                        <TableCell className="text-right">{formatMoney(l.base_debito)}</TableCell>
                                        {tributos.map((t) => (
                                            <TableCell key={t.codigo} className="text-right">
                                                {formatMoney(l.importes[t.codigo])}
                                            </TableCell>
                                        ))}
                                        <TableCell className="text-right">
                                            {esTesorero && cuenta_id && (
                                                <>
                                                    <Button variant="ghost" size="sm" onClick={() => setEditando(editando === l.id ? null : l.id)}>
                                                        Ajustar
                                                    </Button>
                                                    {l.ajustada && (
                                                        <Button
                                                            variant="ghost"
                                                            size="sm"
                                                            onClick={() =>
                                                                router.post(
                                                                    route('liquidaciones.restablecer'),
                                                                    { cuenta_id, fecha: l.fecha },
                                                                    { preserveScroll: true },
                                                                )
                                                            }
                                                        >
                                                            Volver a automático
                                                        </Button>
                                                    )}
                                                </>
                                            )}
                                        </TableCell>
                                    </TableRow>
                                    {editando === l.id && cuenta_id && (
                                        <TableRow key={`ed-${l.id}`}>
                                            <TableCell colSpan={4 + tributos.length} className="p-0 whitespace-normal">
                                                <Ajuste liq={l} cuentaId={cuenta_id} tributos={tributos} onCerrar={() => setEditando(null)} />
                                            </TableCell>
                                        </TableRow>
                                    )}
                                </>
                            ))}
                            {liquidaciones.length === 0 && (
                                <TableRow>
                                    <TableCell colSpan={4 + tributos.length} className="text-muted-foreground h-24 text-center">
                                        No hay días liquidados en este mes.
                                    </TableCell>
                                </TableRow>
                            )}
                        </TableBody>
                    </Table>
                </div>
            </div>
        </AppLayout>
    );
}
