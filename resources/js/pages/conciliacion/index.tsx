import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import { formatDate, formatMoney } from '@/lib/format';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { Head, useForm, usePage } from '@inertiajs/react';
import { FormEventHandler } from 'react';

interface Props {
    fecha: string;
    cuentas: {
        id: number;
        nombre: string;
        tipo: string;
        saldo_calculado: string;
        ultimo_arqueo: { fecha: string; declarado: string; calculado: string; diferencia: string } | null;
    }[];
    total_calculado: string;
    arqueos: { id: number; fecha: string; cuenta: string; declarado: string; calculado: string; diferencia: string; observaciones: string | null }[];
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Conciliación', href: '/conciliacion' }];

const Diferencia = ({ valor }: { valor: string }) => {
    const n = Number(valor);
    return <span className={n === 0 ? 'text-green-600' : 'font-medium text-red-600'}>{n === 0 ? 'Cuadra' : formatMoney(valor)}</span>;
};

export default function Conciliacion({ fecha, cuentas, total_calculado, arqueos }: Props) {
    const { auth } = usePage<SharedData>().props;
    const esTesorero = auth.user.rol === 'tesorero';
    const form = useForm<{ fecha: string; saldos: Record<string, string>; observaciones: string }>({ fecha, saldos: {}, observaciones: '' });

    const enviar: FormEventHandler = (e) => {
        e.preventDefault();
        form.post(route('conciliacion.store'), { preserveScroll: true, onSuccess: () => form.reset('saldos', 'observaciones') });
    };
    const declarado = cuentas.reduce((t, c) => t + (form.data.saldos[c.id] ? Number(form.data.saldos[c.id]) : 0), 0);
    const completo = cuentas.every((c) => form.data.saldos[c.id] !== undefined && form.data.saldos[c.id] !== '');

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Conciliación" />
            <div className="flex max-w-5xl flex-col gap-4 p-4">
                <h1 className="text-2xl font-semibold">Conciliación y arqueo</h1>
                <p className="text-muted-foreground text-sm">
                    Compará el saldo que calcula el sistema con lo que hay realmente en cada cuenta (extracto bancario, efectivo en mano, plazo fijo).
                </p>

                <form onSubmit={enviar} className="grid gap-4">
                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center justify-between">
                                Saldos al día
                                <Input
                                    type="date"
                                    className="w-44"
                                    value={form.data.fecha}
                                    onChange={(e) => form.setData('fecha', e.target.value)}
                                    aria-label="Fecha del arqueo"
                                />
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Cuenta</TableHead>
                                        <TableHead className="text-right">Saldo del sistema (hoy)</TableHead>
                                        <TableHead className="text-right">Saldo real declarado</TableHead>
                                        <TableHead className="text-right">Último arqueo</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {cuentas.map((c) => (
                                        <TableRow key={c.id}>
                                            <TableCell className="font-medium">
                                                {c.nombre} <span className="text-muted-foreground text-xs">({c.tipo})</span>
                                            </TableCell>
                                            <TableCell className="text-right">{formatMoney(c.saldo_calculado)}</TableCell>
                                            <TableCell className="text-right">
                                                {esTesorero ? (
                                                    <Input
                                                        type="number"
                                                        step="0.01"
                                                        className="ml-auto w-44 text-right"
                                                        value={form.data.saldos[c.id] ?? ''}
                                                        onChange={(e) => form.setData('saldos', { ...form.data.saldos, [c.id]: e.target.value })}
                                                        aria-label={`Saldo real de ${c.nombre}`}
                                                    />
                                                ) : (
                                                    '—'
                                                )}
                                            </TableCell>
                                            <TableCell className="text-right text-xs">
                                                {c.ultimo_arqueo ? (
                                                    <>
                                                        {formatDate(c.ultimo_arqueo.fecha)}: <Diferencia valor={c.ultimo_arqueo.diferencia} />
                                                    </>
                                                ) : (
                                                    '—'
                                                )}
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                    <TableRow className="font-semibold">
                                        <TableCell>Total</TableCell>
                                        <TableCell className="text-right">{formatMoney(total_calculado)}</TableCell>
                                        <TableCell className="text-right">{completo ? formatMoney(declarado) : '—'}</TableCell>
                                        <TableCell className="text-right">
                                            {completo ? (
                                                <Diferencia valor={String(Math.round((declarado - Number(total_calculado)) * 100) / 100)} />
                                            ) : (
                                                ''
                                            )}
                                        </TableCell>
                                    </TableRow>
                                </TableBody>
                            </Table>
                            {esTesorero && (
                                <div className="mt-4 flex flex-wrap items-end gap-3">
                                    <div className="grid flex-1 gap-1">
                                        <Label htmlFor="obs">Observaciones</Label>
                                        <Input
                                            id="obs"
                                            value={form.data.observaciones}
                                            onChange={(e) => form.setData('observaciones', e.target.value)}
                                        />
                                    </div>
                                    <Button type="submit" disabled={form.processing || Object.values(form.data.saldos).every((v) => v === '')}>
                                        Guardar arqueo
                                    </Button>
                                </div>
                            )}
                        </CardContent>
                    </Card>
                </form>

                <Card>
                    <CardHeader>
                        <CardTitle>Historial de arqueos</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Fecha</TableHead>
                                    <TableHead>Cuenta</TableHead>
                                    <TableHead className="text-right">Sistema</TableHead>
                                    <TableHead className="text-right">Declarado</TableHead>
                                    <TableHead className="text-right">Diferencia</TableHead>
                                    <TableHead>Observaciones</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {arqueos.map((a) => (
                                    <TableRow key={a.id}>
                                        <TableCell>{formatDate(a.fecha)}</TableCell>
                                        <TableCell>{a.cuenta}</TableCell>
                                        <TableCell className="text-right">{formatMoney(a.calculado)}</TableCell>
                                        <TableCell className="text-right">{formatMoney(a.declarado)}</TableCell>
                                        <TableCell className="text-right">
                                            <Diferencia valor={a.diferencia} />
                                        </TableCell>
                                        <TableCell className="text-muted-foreground">{a.observaciones}</TableCell>
                                    </TableRow>
                                ))}
                                {arqueos.length === 0 && (
                                    <TableRow>
                                        <TableCell colSpan={6} className="text-muted-foreground h-16 text-center">
                                            Todavía no hay arqueos.
                                        </TableCell>
                                    </TableRow>
                                )}
                            </TableBody>
                        </Table>
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
