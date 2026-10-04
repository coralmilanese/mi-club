import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { NativeSelect } from '@/components/ui/native-select';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import { formatDate, formatMoney, formatPeriodo } from '@/lib/format';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { Check, X } from 'lucide-react';
import { FormEventHandler, useState } from 'react';

interface Props {
    socio: {
        id: number;
        nro_socio: number | null;
        apellido: string;
        nombre: string;
        nombre_completo: string;
        genero: string | null;
        nacionalidad: string | null;
        dni: string | null;
        direccion: string | null;
        ciudad: string | null;
        provincia: string | null;
        email: string | null;
        telefono: string | null;
        profesion: string | null;
        fecha_nacimiento: string | null;
        fecha_asociacion: string | null;
        fecha_inicio_actividad: string | null;
        fecha_baja: string | null;
        motivo_baja: string | null;
        observaciones: string | null;
        categoria: string;
        sede: string;
        estado: string;
        estado_label: string;
    };
    estados: { id: number; estado: string; desde: string | null; hasta: string | null; motivo: string | null }[];
    grupo: {
        id: number;
        nombre: string;
        es_titular: boolean;
        titular: { id: number; nombre: string } | null;
        miembros: { id: number; nombre: string }[];
    } | null;
    documentos: {
        id: number;
        tipo: string;
        nombre_original: string | null;
        tiene_archivo: boolean;
        subido_at: string | null;
        observaciones: string | null;
    }[];
    checklist: { tipo_id: number; nombre: string; obligatorio: boolean; entregado: boolean }[];
    puede_reingresar: boolean;
    plan_actual: { id: number; plan_id: number; desde: string } | null;
    planes_historial: { id: number; plan: string; desde: string; hasta: string | null; motivo: string | null }[];
    planes_disponibles: { id: number; nombre: string }[];
    cuotas: {
        id: number;
        periodo: string;
        plan: string;
        de_adherente: string | null;
        cubierta_por_titular: boolean;
        estado: string;
        estado_label: string;
        devengado: string;
        cobrado: string | null;
        imputado: string;
        deuda: string;
    }[];
    deuda_total: string;
    saldo_a_favor: string;
    pagos: {
        id: number;
        fecha: string;
        importe: string;
        medio: string | null;
        cuenta: string;
        estado: string;
        estado_label: string;
        concepto: string | null;
        periodos: string;
        comprobante_url: string | null;
    }[];
}

const hoy = () => new Date().toISOString().slice(0, 10);

function Dato({ etiqueta, valor }: { etiqueta: string; valor: React.ReactNode }) {
    return (
        <div>
            <dt className="text-muted-foreground text-xs">{etiqueta}</dt>
            <dd className="text-sm">{valor || '—'}</dd>
        </div>
    );
}

function AccionEstado({ socio, reingreso }: { socio: Props['socio']; reingreso: boolean }) {
    const form = useForm({ fecha: hoy(), motivo: '' });
    const enviar: FormEventHandler = (e) => {
        e.preventDefault();
        form.post(route(reingreso ? 'socios.reingreso' : 'socios.baja', socio.id), { preserveScroll: true, onSuccess: () => form.reset('motivo') });
    };

    return (
        <form onSubmit={enviar} className="grid gap-3 md:max-w-md">
            <div className="grid gap-2">
                <Label htmlFor="fecha-estado">Fecha</Label>
                <Input id="fecha-estado" type="date" value={form.data.fecha} onChange={(e) => form.setData('fecha', e.target.value)} />
                <InputError message={form.errors.fecha} />
            </div>
            <div className="grid gap-2">
                <Label htmlFor="motivo-estado">Motivo{reingreso ? ' (opcional)' : ''}</Label>
                <Textarea id="motivo-estado" value={form.data.motivo} onChange={(e) => form.setData('motivo', e.target.value)} />
                <InputError message={form.errors.motivo} />
            </div>
            <Button type="submit" variant={reingreso ? 'default' : 'destructive'} disabled={form.processing}>
                {reingreso ? 'Registrar reingreso' : 'Dar de baja'}
            </Button>
        </form>
    );
}

function AsignarPlan({ socioId, planes }: { socioId: number; planes: Props['planes_disponibles'] }) {
    const form = useForm({ plan_id: '', desde: hoy().slice(0, 8) + '01', motivo: '' });
    const enviar: FormEventHandler = (e) => {
        e.preventDefault();
        form.post(route('socios.plan.store', socioId), { preserveScroll: true, onSuccess: () => form.reset('plan_id', 'motivo') });
    };
    const errores = form.errors as Record<string, string>;

    return (
        <form onSubmit={enviar} className="flex flex-wrap items-start gap-2 border-t pt-3">
            <div className="grid gap-1">
                <NativeSelect className="w-56" value={form.data.plan_id} onChange={(e) => form.setData('plan_id', e.target.value)} aria-label="Plan">
                    <option value="">Asignar plan…</option>
                    {planes.map((p) => (
                        <option key={p.id} value={p.id}>
                            {p.nombre}
                        </option>
                    ))}
                </NativeSelect>
                <InputError message={errores.plan_id} />
            </div>
            <div className="grid gap-1">
                <Input
                    type="date"
                    className="w-40"
                    value={form.data.desde}
                    onChange={(e) => form.setData('desde', e.target.value)}
                    aria-label="Desde"
                />
                <InputError message={errores.desde} />
            </div>
            <Button type="submit" size="sm" variant="secondary" disabled={form.processing || !form.data.plan_id}>
                Asignar
            </Button>
        </form>
    );
}

function SubirDocumento({ socioId, checklist }: { socioId: number; checklist: Props['checklist'] }) {
    const form = useForm<{ tipo_documento_id: string; archivo: File | null }>({ tipo_documento_id: '', archivo: null });
    const enviar: FormEventHandler = (e) => {
        e.preventDefault();
        form.post(route('socios.documentos.store', socioId), { preserveScroll: true, forceFormData: true, onSuccess: () => form.reset() });
    };

    return (
        <form onSubmit={enviar} className="flex flex-wrap items-end gap-2">
            <div className="grid gap-1">
                <Label htmlFor="tipo-doc">Tipo</Label>
                <NativeSelect
                    id="tipo-doc"
                    className="w-56"
                    value={form.data.tipo_documento_id}
                    onChange={(e) => form.setData('tipo_documento_id', e.target.value)}
                >
                    <option value="">Elegí un tipo…</option>
                    {checklist.map((c) => (
                        <option key={c.tipo_id} value={c.tipo_id}>
                            {c.nombre}
                        </option>
                    ))}
                </NativeSelect>
                <InputError message={form.errors.tipo_documento_id} />
            </div>
            <div className="grid gap-1">
                <Label htmlFor="archivo-doc">Archivo (JPG, PNG o PDF)</Label>
                <Input
                    id="archivo-doc"
                    type="file"
                    accept=".jpg,.jpeg,.png,.pdf"
                    onChange={(e) => form.setData('archivo', e.target.files?.[0] ?? null)}
                />
                <InputError message={form.errors.archivo} />
            </div>
            <Button type="submit" variant="secondary" disabled={form.processing || !form.data.archivo || !form.data.tipo_documento_id}>
                Subir
            </Button>
        </form>
    );
}

export default function SocioShow({
    socio,
    estados,
    grupo,
    documentos,
    checklist,
    puede_reingresar,
    plan_actual,
    planes_historial,
    planes_disponibles,
    cuotas,
    deuda_total,
    saldo_a_favor,
    pagos,
}: Props) {
    const { auth } = usePage<SharedData>().props;
    const esTesorero = auth.user.rol === 'tesorero';
    const [mostrarEstado, setMostrarEstado] = useState(false);

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Socios', href: '/socios' },
        { title: socio.nombre_completo, href: '#' },
    ];
    const faltantes = checklist.filter((c) => c.obligatorio && !c.entregado);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={socio.nombre_completo} />
            <div className="flex flex-col gap-4 p-4">
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <div className="flex items-center gap-3">
                        <h1 className="text-2xl font-semibold">{socio.nombre_completo}</h1>
                        <Badge variant={socio.estado === 'baja' ? 'secondary' : 'default'}>{socio.estado_label}</Badge>
                        <Badge variant="outline">{socio.categoria}</Badge>
                        <Badge variant="outline">{socio.sede}</Badge>
                    </div>
                    <div className="flex gap-2">
                        <Button variant="outline" asChild>
                            <a href={route('socios.estado-cuenta', socio.id)} target="_blank" rel="noreferrer">
                                Estado de cuenta (PDF)
                            </a>
                        </Button>
                        {esTesorero && (
                            <>
                                <Button variant="outline" asChild>
                                    <Link href={route('socios.edit', socio.id)}>Editar</Link>
                                </Button>
                                <Button variant={puede_reingresar ? 'default' : 'destructive'} onClick={() => setMostrarEstado((v) => !v)}>
                                    {puede_reingresar ? 'Reingresar' : 'Dar de baja'}
                                </Button>
                            </>
                        )}
                    </div>
                </div>

                {esTesorero && mostrarEstado && (
                    <Card>
                        <CardContent className="pt-6">
                            <AccionEstado socio={socio} reingreso={puede_reingresar} />
                        </CardContent>
                    </Card>
                )}

                <div className="grid gap-4 lg:grid-cols-2">
                    <Card>
                        <CardHeader>
                            <CardTitle>Datos</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <dl className="grid grid-cols-2 gap-3">
                                <Dato etiqueta="Nº de socio" valor={socio.nro_socio} />
                                <Dato etiqueta="DNI" valor={socio.dni} />
                                <Dato etiqueta="Nacimiento" valor={formatDate(socio.fecha_nacimiento)} />
                                <Dato etiqueta="Nacionalidad" valor={socio.nacionalidad} />
                                <Dato etiqueta="Email" valor={socio.email} />
                                <Dato etiqueta="Teléfono" valor={socio.telefono} />
                                <Dato etiqueta="Dirección" valor={[socio.direccion, socio.ciudad, socio.provincia].filter(Boolean).join(', ')} />
                                <Dato etiqueta="Profesión" valor={socio.profesion} />
                                <Dato etiqueta="Asociación" valor={formatDate(socio.fecha_asociacion)} />
                                <Dato etiqueta="Inicio de actividad" valor={formatDate(socio.fecha_inicio_actividad)} />
                                {socio.fecha_baja && <Dato etiqueta="Baja" valor={`${formatDate(socio.fecha_baja)} — ${socio.motivo_baja ?? ''}`} />}
                            </dl>
                            {socio.observaciones && <p className="text-muted-foreground mt-4 text-sm whitespace-pre-line">{socio.observaciones}</p>}
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle>Plan de cuota</CardTitle>
                        </CardHeader>
                        <CardContent className="grid gap-3 text-sm">
                            {plan_actual ? (
                                <p>
                                    <span className="font-medium">{planes_historial.find((p) => p.hasta === null)?.plan}</span>
                                    <span className="text-muted-foreground"> desde {formatDate(plan_actual.desde)}</span>
                                </p>
                            ) : (
                                <p className="text-amber-600">Sin plan asignado: no se le devengan cuotas.</p>
                            )}
                            {planes_historial.length > 1 && (
                                <ul className="text-muted-foreground grid gap-1 text-xs">
                                    {planes_historial.map((p) => (
                                        <li key={p.id}>
                                            {p.plan}: {formatDate(p.desde)} → {p.hasta ? formatDate(p.hasta) : 'vigente'}
                                            {p.motivo ? ` (${p.motivo})` : ''}
                                        </li>
                                    ))}
                                </ul>
                            )}
                            {esTesorero && !socio.fecha_baja && <AsignarPlan socioId={socio.id} planes={planes_disponibles} />}
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle>Grupo familiar</CardTitle>
                        </CardHeader>
                        <CardContent className="text-sm">
                            {grupo ? (
                                <div className="grid gap-2">
                                    <p className="font-medium">{grupo.nombre}</p>
                                    {grupo.es_titular ? (
                                        <p className="text-muted-foreground">Es el titular del grupo.</p>
                                    ) : (
                                        <p className="text-muted-foreground">
                                            Cubierto por el grupo familiar de{' '}
                                            {grupo.titular && (
                                                <Link href={route('socios.show', grupo.titular.id)} className="underline">
                                                    {grupo.titular.nombre}
                                                </Link>
                                            )}
                                            .
                                        </p>
                                    )}
                                    {grupo.miembros.length > 0 && (
                                        <ul className="list-disc pl-5">
                                            {grupo.miembros.map((m) => (
                                                <li key={m.id}>
                                                    <Link href={route('socios.show', m.id)} className="hover:underline">
                                                        {m.nombre}
                                                    </Link>
                                                </li>
                                            ))}
                                        </ul>
                                    )}
                                </div>
                            ) : (
                                <p className="text-muted-foreground">
                                    No pertenece a ningún grupo.{' '}
                                    <Link href={route('grupos-familiares.index')} className="underline">
                                        Administrar grupos
                                    </Link>
                                </p>
                            )}
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle>Documentación</CardTitle>
                        </CardHeader>
                        <CardContent className="grid gap-4">
                            {faltantes.length > 0 && <p className="text-sm text-amber-600">Falta: {faltantes.map((f) => f.nombre).join(', ')}</p>}
                            <ul className="grid gap-1 text-sm">
                                {checklist.map((c) => (
                                    <li key={c.tipo_id} className="flex items-center gap-2">
                                        {c.entregado ? (
                                            <Check className="size-4 text-green-600" />
                                        ) : (
                                            <X className={`size-4 ${c.obligatorio ? 'text-red-600' : 'text-muted-foreground'}`} />
                                        )}
                                        {c.nombre}
                                        {c.obligatorio && <span className="text-muted-foreground text-xs">(obligatorio)</span>}
                                    </li>
                                ))}
                            </ul>
                            {documentos.length > 0 && (
                                <ul className="grid gap-1 border-t pt-3 text-sm">
                                    {documentos.map((d) => (
                                        <li key={d.id} className="flex items-center justify-between gap-2">
                                            <span>
                                                {d.tipo}
                                                {d.tiene_archivo ? (
                                                    <>
                                                        {' · '}
                                                        <a
                                                            className="underline"
                                                            href={route('socios.documentos.show', [socio.id, d.id])}
                                                            target="_blank"
                                                            rel="noreferrer"
                                                        >
                                                            {d.nombre_original}
                                                        </a>
                                                    </>
                                                ) : (
                                                    <span className="text-muted-foreground"> · entregado (sin archivo digital)</span>
                                                )}
                                            </span>
                                            {esTesorero && (
                                                <Button
                                                    variant="ghost"
                                                    size="sm"
                                                    onClick={() =>
                                                        confirm('¿Eliminar este documento?') &&
                                                        router.delete(route('socios.documentos.destroy', [socio.id, d.id]), { preserveScroll: true })
                                                    }
                                                >
                                                    Eliminar
                                                </Button>
                                            )}
                                        </li>
                                    ))}
                                </ul>
                            )}
                            {esTesorero && checklist.length > 0 && <SubirDocumento socioId={socio.id} checklist={checklist} />}
                        </CardContent>
                    </Card>

                    <Card className="lg:col-span-2">
                        <CardHeader>
                            <CardTitle className="flex items-center justify-between">
                                Cuotas
                                <span className={`text-sm font-normal ${Number(deuda_total) > 0 ? 'text-red-600' : 'text-muted-foreground'}`}>
                                    Deuda a valores de hoy: {formatMoney(deuda_total, { entero: true })}
                                </span>
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            {cuotas.length === 0 ? (
                                <p className="text-muted-foreground text-sm">Todavía no tiene cuotas devengadas.</p>
                            ) : (
                                <table className="w-full text-sm">
                                    <thead className="text-muted-foreground text-left text-xs">
                                        <tr>
                                            <th className="py-1">Período</th>
                                            <th>Plan</th>
                                            <th>Estado</th>
                                            <th className="text-right">Devengado</th>
                                            <th className="text-right">Cobrado</th>
                                            <th className="text-right">Debe hoy</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {cuotas.map((c) => (
                                            <tr key={c.id} className="border-t">
                                                <td className="py-1 capitalize">{formatPeriodo(c.periodo)}</td>
                                                <td>
                                                    {c.plan}
                                                    {c.de_adherente && <span className="text-muted-foreground"> · {c.de_adherente}</span>}
                                                    {c.cubierta_por_titular && <span className="text-muted-foreground"> · la paga el titular</span>}
                                                </td>
                                                <td>{c.estado_label}</td>
                                                <td className="text-right">{formatMoney(c.devengado, { entero: true })}</td>
                                                <td className="text-right">{c.cobrado ? formatMoney(c.cobrado, { entero: true }) : '—'}</td>
                                                <td className="text-right font-medium">
                                                    {Number(c.deuda) > 0 && !c.cubierta_por_titular ? formatMoney(c.deuda, { entero: true }) : '—'}
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            )}
                        </CardContent>
                    </Card>

                    <Card className="lg:col-span-2">
                        <CardHeader>
                            <CardTitle className="flex flex-wrap items-center justify-between gap-2">
                                Pagos
                                <span className="flex items-center gap-3 text-sm font-normal">
                                    {Number(saldo_a_favor) > 0 && (
                                        <span className="text-green-700 dark:text-green-400">
                                            Saldo a favor: {formatMoney(saldo_a_favor, { entero: true })}
                                        </span>
                                    )}
                                    {esTesorero && !socio.fecha_baja && (
                                        <Button size="sm" asChild>
                                            <Link href={route('pagos.create', { socio_id: socio.id })}>Registrar pago</Link>
                                        </Button>
                                    )}
                                </span>
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            {pagos.length === 0 ? (
                                <p className="text-muted-foreground text-sm">Todavía no hay pagos registrados a su nombre.</p>
                            ) : (
                                <table className="w-full text-sm">
                                    <thead className="text-muted-foreground text-left text-xs">
                                        <tr>
                                            <th className="py-1">Fecha</th>
                                            <th>Cuotas</th>
                                            <th>Medio / cuenta</th>
                                            <th className="text-right">Importe</th>
                                            <th />
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {pagos.map((p) => (
                                            <tr
                                                key={p.id}
                                                className={`border-t ${p.estado === 'anulado' ? 'text-muted-foreground line-through' : ''}`}
                                            >
                                                <td className="py-1">{formatDate(p.fecha)}</td>
                                                <td className="capitalize">
                                                    {p.periodos || <span className="text-muted-foreground normal-case">saldo a favor</span>}
                                                </td>
                                                <td>
                                                    {p.medio} · {p.cuenta}
                                                </td>
                                                <td className="text-right">{formatMoney(p.importe)}</td>
                                                <td className="text-right">
                                                    {p.comprobante_url && (
                                                        <a className="underline" href={p.comprobante_url} target="_blank" rel="noreferrer">
                                                            comprobante
                                                        </a>
                                                    )}
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            )}
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle>Historial de estados</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <ul className="grid gap-2 text-sm">
                                {estados.map((e) => (
                                    <li key={e.id}>
                                        <span className="font-medium">{e.estado}</span>{' '}
                                        <span className="text-muted-foreground">
                                            desde {formatDate(e.desde)}
                                            {e.hasta ? ` hasta ${formatDate(e.hasta)}` : ' (vigente)'}
                                        </span>
                                        {e.motivo && <p className="text-muted-foreground text-xs">{e.motivo}</p>}
                                    </li>
                                ))}
                            </ul>
                        </CardContent>
                    </Card>
                </div>
            </div>
        </AppLayout>
    );
}
