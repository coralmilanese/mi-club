import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { NativeSelect } from '@/components/ui/native-select';
import AppLayout from '@/layouts/app-layout';
import { formatDate, formatMoney, formatPeriodo } from '@/lib/format';
import { type BreadcrumbItem } from '@/types';
import { Head, router, useForm } from '@inertiajs/react';
import { FormEventHandler, useEffect, useState } from 'react';

interface Props {
    socios: { id: number; nombre: string }[];
    medios: { id: number; nombre: string; codigo: string; cuenta_default_id: number | null }[];
    cuentas: { id: number; nombre: string; tipo: string }[];
    seleccion: { socio_id: number | null; fecha: string; importe: string | null };
    adeudadas: { id: number; periodo: string; plan: string; socio: string; deuda: string; devengado: string }[];
    propuesta: { imputaciones: { cuota_id: number; importe: string; completa: boolean }[]; sobrante: string; exacto: boolean } | null;
    saldo_a_favor: string;
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Pagos', href: '/pagos' },
    { title: 'Registrar', href: '#' },
];

export default function PagoCreate({ socios, medios, cuentas, seleccion, adeudadas, propuesta, saldo_a_favor }: Props) {
    const form = useForm<{
        socio_id: string;
        fecha: string;
        importe: string;
        medio_pago_id: string;
        cuenta_id: string;
        concepto: string;
        referencia_externa: string;
        comprobante: File | null;
        imputaciones: Record<string, string>;
    }>({
        socio_id: seleccion.socio_id ? String(seleccion.socio_id) : '',
        fecha: seleccion.fecha,
        importe: seleccion.importe ?? '',
        medio_pago_id: String(medios[0]?.id ?? ''),
        cuenta_id: String(medios[0]?.cuenta_default_id ?? cuentas[0]?.id ?? ''),
        concepto: '',
        referencia_externa: '',
        comprobante: null,
        imputaciones: {},
    });
    const [manual, setManual] = useState(false);
    const errores = form.errors as Record<string, string>;

    // La propuesta de imputación se recalcula en el servidor cada vez que cambia socio, fecha o importe.
    useEffect(() => {
        const t = setTimeout(() => {
            if (form.data.socio_id) {
                router.get(
                    route('pagos.create'),
                    { socio_id: form.data.socio_id, fecha: form.data.fecha, importe: form.data.importe || undefined },
                    { preserveState: true, preserveScroll: true, replace: true, only: ['adeudadas', 'propuesta', 'saldo_a_favor', 'seleccion'] },
                );
            }
        }, 350);
        return () => clearTimeout(t);
    }, [form.data.socio_id, form.data.fecha, form.data.importe]);

    const elegirMedio = (id: string) => {
        const m = medios.find((x) => String(x.id) === id);
        form.setData((d) => ({ ...d, medio_pago_id: id, cuenta_id: m?.cuenta_default_id ? String(m.cuenta_default_id) : d.cuenta_id }));
    };
    const medio = medios.find((m) => String(m.id) === form.data.medio_pago_id);
    const cuentasVisibles = medio?.codigo === 'efectivo' ? cuentas.filter((c) => c.tipo === 'efectivo') : cuentas;

    const enviar: FormEventHandler = (e) => {
        e.preventDefault();
        form.transform((d) => ({ ...d, imputaciones: manual ? d.imputaciones : undefined }));
        form.post(route('pagos.store'), { forceFormData: true });
    };

    const importeManual = Object.values(form.data.imputaciones).reduce((t, v) => t + (Number(v) || 0), 0);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Registrar pago" />
            <form onSubmit={enviar} className="flex max-w-5xl flex-col gap-4 p-4">
                <h1 className="text-2xl font-semibold">Registrar pago</h1>

                <div className="grid gap-4 md:grid-cols-3">
                    <div className="grid gap-1 md:col-span-2">
                        <Label htmlFor="p-socio">Socio que paga</Label>
                        <NativeSelect
                            id="p-socio"
                            value={form.data.socio_id}
                            onChange={(e) => form.setData((d) => ({ ...d, socio_id: e.target.value, imputaciones: {} }))}
                        >
                            <option value="">Elegí un socio…</option>
                            {socios.map((s) => (
                                <option key={s.id} value={s.id}>
                                    {s.nombre}
                                </option>
                            ))}
                        </NativeSelect>
                        <InputError message={errores.socio_id} />
                    </div>
                    <div className="grid gap-1">
                        <Label htmlFor="p-fecha">Fecha del comprobante</Label>
                        <Input id="p-fecha" type="date" value={form.data.fecha} onChange={(e) => form.setData('fecha', e.target.value)} />
                        <InputError message={errores.fecha} />
                    </div>
                    <div className="grid gap-1">
                        <Label htmlFor="p-importe">Importe transferido ($, bruto)</Label>
                        <Input
                            id="p-importe"
                            type="number"
                            step="0.01"
                            min="0"
                            value={form.data.importe}
                            onChange={(e) => form.setData('importe', e.target.value)}
                        />
                        <InputError message={errores.importe} />
                    </div>
                    <div className="grid gap-1">
                        <Label htmlFor="p-medio">Medio</Label>
                        <NativeSelect id="p-medio" value={form.data.medio_pago_id} onChange={(e) => elegirMedio(e.target.value)}>
                            {medios.map((m) => (
                                <option key={m.id} value={m.id}>
                                    {m.nombre}
                                </option>
                            ))}
                        </NativeSelect>
                    </div>
                    <div className="grid gap-1">
                        <Label htmlFor="p-cuenta">Cuenta</Label>
                        <NativeSelect id="p-cuenta" value={form.data.cuenta_id} onChange={(e) => form.setData('cuenta_id', e.target.value)}>
                            {cuentasVisibles.map((c) => (
                                <option key={c.id} value={c.id}>
                                    {c.nombre}
                                </option>
                            ))}
                        </NativeSelect>
                        <InputError message={errores.cuenta_id} />
                    </div>
                    <div className="grid gap-1">
                        <Label htmlFor="p-ref">Nº de operación (opcional)</Label>
                        <Input id="p-ref" value={form.data.referencia_externa} onChange={(e) => form.setData('referencia_externa', e.target.value)} />
                        <InputError message={errores.referencia_externa} />
                    </div>
                    <div className="grid gap-1">
                        <Label htmlFor="p-comp">Comprobante (JPG, PNG o PDF)</Label>
                        <Input
                            id="p-comp"
                            type="file"
                            accept=".jpg,.jpeg,.png,.pdf"
                            onChange={(e) => form.setData('comprobante', e.target.files?.[0] ?? null)}
                        />
                        <InputError message={errores.comprobante} />
                    </div>
                    <div className="grid gap-1 md:col-span-3">
                        <Label htmlFor="p-concepto">Concepto (opcional: si lo dejás vacío se arma solo, ej. "Gelos Gabriel septiembre")</Label>
                        <Input id="p-concepto" value={form.data.concepto} onChange={(e) => form.setData('concepto', e.target.value)} />
                    </div>
                </div>

                {form.data.socio_id && (
                    <Card>
                        <CardHeader>
                            <CardTitle className="flex flex-wrap items-center justify-between gap-2 text-base">
                                A qué cuotas se imputa
                                <label className="flex items-center gap-2 text-sm font-normal">
                                    <input type="checkbox" checked={manual} onChange={(e) => setManual(e.target.checked)} />
                                    Reasignar a mano
                                </label>
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="grid gap-3 text-sm">
                            <p className="text-muted-foreground text-xs">
                                Las cuotas atrasadas se cobran a la tarifa vigente el día del pago ({formatDate(form.data.fecha)}).
                            </p>
                            {Number(saldo_a_favor) > 0 && (
                                <p className="text-green-700 dark:text-green-400">
                                    Saldo a favor previo: {formatMoney(saldo_a_favor)} (se imputa solo al devengar el próximo mes).
                                </p>
                            )}
                            {adeudadas.length === 0 && (
                                <p className="text-muted-foreground">No tiene cuotas pendientes: todo el pago quedará como saldo a favor.</p>
                            )}

                            {adeudadas.length > 0 && (
                                <table className="w-full">
                                    <thead className="text-muted-foreground text-left text-xs">
                                        <tr>
                                            <th className="py-1">Período</th>
                                            <th>Cuota</th>
                                            <th className="text-right">Debe (a tarifa de la fecha)</th>
                                            <th className="text-right">{manual ? 'Imputar' : 'Se imputa'}</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {adeudadas.map((c) => {
                                            const propuesto = propuesta?.imputaciones.find((i) => i.cuota_id === c.id);
                                            return (
                                                <tr key={c.id} className="border-t">
                                                    <td className="py-1 capitalize">{formatPeriodo(c.periodo)}</td>
                                                    <td>
                                                        {c.plan} <span className="text-muted-foreground">· {c.socio}</span>
                                                    </td>
                                                    <td className="text-right">{formatMoney(c.deuda)}</td>
                                                    <td className="text-right">
                                                        {manual ? (
                                                            <Input
                                                                type="number"
                                                                step="0.01"
                                                                min="0"
                                                                className="ml-auto h-8 w-32 text-right"
                                                                value={form.data.imputaciones[c.id] ?? ''}
                                                                onChange={(e) =>
                                                                    form.setData('imputaciones', {
                                                                        ...form.data.imputaciones,
                                                                        [c.id]: e.target.value,
                                                                    })
                                                                }
                                                                aria-label={`Imputar a ${formatPeriodo(c.periodo)}`}
                                                            />
                                                        ) : propuesto ? (
                                                            <span
                                                                className={
                                                                    propuesto.completa
                                                                        ? 'font-medium text-green-700 dark:text-green-400'
                                                                        : 'font-medium text-amber-600'
                                                                }
                                                            >
                                                                {formatMoney(propuesto.importe)}
                                                                {!propuesto.completa && ' (parcial)'}
                                                            </span>
                                                        ) : (
                                                            '—'
                                                        )}
                                                    </td>
                                                </tr>
                                            );
                                        })}
                                    </tbody>
                                </table>
                            )}
                            {errores.imputaciones && <InputError message={errores.imputaciones} />}

                            {!manual && propuesta && Number(propuesta.sobrante) > 0 && (
                                <p className="text-amber-600">Sobran {formatMoney(propuesta.sobrante)}: quedan como saldo a favor del socio.</p>
                            )}
                            {!manual && propuesta && !propuesta.exacto && Number(propuesta.sobrante) === 0 && (
                                <p className="text-amber-600">El importe no alcanza a cubrir la última cuota: queda como pago parcial.</p>
                            )}
                            {manual && (
                                <p className="text-muted-foreground">
                                    Imputado a mano: {formatMoney(importeManual)} de {formatMoney(form.data.importe || 0)}.
                                </p>
                            )}
                        </CardContent>
                    </Card>
                )}

                <div>
                    <Button type="submit" disabled={form.processing || !form.data.socio_id || !form.data.importe}>
                        Registrar pago
                    </Button>
                    <span className="text-muted-foreground ml-3 text-xs">
                        Se asienta en el libro con la fecha del comprobante y se recalculan los impuestos de ese día.
                    </span>
                </div>
            </form>
        </AppLayout>
    );
}
