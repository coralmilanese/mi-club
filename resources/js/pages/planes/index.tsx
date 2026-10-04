import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { formatDate, formatMoney } from '@/lib/format';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { FormEventHandler } from 'react';

interface Tarifa {
    id: number;
    importe: string;
    vigencia_desde: string;
    vigencia_hasta: string | null;
}
interface PlanItem {
    id: number;
    tipo_plan: string;
    nombre: string;
    descripcion: string | null;
    genera_cuota: boolean;
    es_adicional_familiar: boolean;
    activo: boolean;
    socios_count: number;
    importe_vigente: string | null;
    tarifas: Tarifa[];
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Planes y tarifas', href: '/planes' }];

function NuevaTarifa({ plan }: { plan: PlanItem }) {
    const form = useForm({ importe: '', vigencia_desde: '' });
    const enviar: FormEventHandler = (e) => {
        e.preventDefault();
        form.post(route('planes.tarifas.store', plan.id), { preserveScroll: true, onSuccess: () => form.reset() });
    };

    return (
        <form onSubmit={enviar} className="flex flex-wrap items-start gap-2 border-t pt-3">
            <div className="grid gap-1">
                <Label htmlFor={`imp-${plan.id}`} className="text-xs">
                    Nueva tarifa ($)
                </Label>
                <Input
                    id={`imp-${plan.id}`}
                    type="number"
                    step="0.01"
                    min="0"
                    className="w-32"
                    value={form.data.importe}
                    onChange={(e) => form.setData('importe', e.target.value)}
                />
                <InputError message={form.errors.importe} />
            </div>
            <div className="grid gap-1">
                <Label htmlFor={`vig-${plan.id}`} className="text-xs">
                    Vigente desde
                </Label>
                <Input
                    id={`vig-${plan.id}`}
                    type="date"
                    className="w-40"
                    value={form.data.vigencia_desde}
                    onChange={(e) => form.setData('vigencia_desde', e.target.value)}
                />
                <InputError message={form.errors.vigencia_desde} />
            </div>
            <Button
                type="submit"
                size="sm"
                variant="secondary"
                className="mt-5"
                disabled={form.processing || !form.data.importe || !form.data.vigencia_desde}
            >
                Registrar
            </Button>
        </form>
    );
}

function NuevoPlan() {
    const form = useForm<{ tipo_plan: string; nombre: string; genera_cuota: boolean; es_adicional_familiar: boolean }>({
        tipo_plan: '',
        nombre: '',
        genera_cuota: true,
        es_adicional_familiar: false,
    });
    const enviar: FormEventHandler = (e) => {
        e.preventDefault();
        form.post(route('planes.store'), { preserveScroll: true, onSuccess: () => form.reset() });
    };

    return (
        <form onSubmit={enviar} className="flex flex-wrap items-start gap-3">
            <div className="grid gap-1">
                <Label htmlFor="np-nombre">Nombre</Label>
                <Input id="np-nombre" value={form.data.nombre} onChange={(e) => form.setData('nombre', e.target.value)} placeholder="Socio cadete" />
                <InputError message={form.errors.nombre} />
            </div>
            <div className="grid gap-1">
                <Label htmlFor="np-tipo">Código (tipo_plan)</Label>
                <Input
                    id="np-tipo"
                    value={form.data.tipo_plan}
                    onChange={(e) => form.setData('tipo_plan', e.target.value)}
                    placeholder="socio_cadete"
                />
                <InputError message={form.errors.tipo_plan} />
            </div>
            <Label className="mt-6 flex items-center gap-2">
                <Checkbox checked={form.data.genera_cuota} onCheckedChange={(v) => form.setData('genera_cuota', v === true)} />
                Genera cuota
            </Label>
            <Label className="mt-6 flex items-center gap-2">
                <Checkbox checked={form.data.es_adicional_familiar} onCheckedChange={(v) => form.setData('es_adicional_familiar', v === true)} />
                Adicional familiar
            </Label>
            <Button type="submit" className="mt-5" disabled={form.processing || !form.data.nombre || !form.data.tipo_plan}>
                Crear plan
            </Button>
        </form>
    );
}

export default function PlanesIndex({ planes }: { planes: PlanItem[] }) {
    const { auth } = usePage<SharedData>().props;
    const esTesorero = auth.user.rol === 'tesorero';

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Planes y tarifas" />
            <div className="flex flex-col gap-4 p-4">
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <h1 className="text-2xl font-semibold">Planes y tarifas</h1>
                    {esTesorero && (
                        <Button variant="outline" asChild>
                            <Link href={route('planes.masiva')}>Asignación masiva de planes</Link>
                        </Button>
                    )}
                </div>
                <p className="text-muted-foreground max-w-3xl text-sm">
                    Las tarifas no se editan: cada aumento es una tarifa nueva con su fecha de vigencia y cierra la anterior. Las cuotas impagas se
                    cobran a la tarifa vigente el día del pago.
                </p>

                {esTesorero && <NuevoPlan />}

                <div className="grid gap-4 lg:grid-cols-2">
                    {planes.map((p) => (
                        <Card key={p.id} className={p.activo ? '' : 'opacity-60'}>
                            <CardHeader>
                                <CardTitle className="flex flex-wrap items-center gap-2">
                                    {p.nombre}
                                    <Badge variant="outline">{p.tipo_plan}</Badge>
                                    {!p.genera_cuota && <Badge variant="secondary">No paga cuota</Badge>}
                                    {p.es_adicional_familiar && <Badge variant="secondary">Adicional familiar</Badge>}
                                    {!p.activo && <Badge variant="secondary">Inactivo</Badge>}
                                </CardTitle>
                            </CardHeader>
                            <CardContent className="grid gap-3 text-sm">
                                <p>
                                    <span className="text-muted-foreground">Vigente hoy: </span>
                                    <span className="font-medium">{p.genera_cuota ? formatMoney(p.importe_vigente, { entero: true }) : '—'}</span>
                                    <span className="text-muted-foreground"> · {p.socios_count} socios</span>
                                </p>
                                {p.descripcion && <p className="text-muted-foreground">{p.descripcion}</p>}
                                {p.tarifas.length > 0 && (
                                    <ul className="grid gap-1">
                                        {p.tarifas.map((t) => (
                                            <li key={t.id} className="flex justify-between">
                                                <span>{formatMoney(t.importe, { entero: true })}</span>
                                                <span className="text-muted-foreground">
                                                    {formatDate(t.vigencia_desde)} → {t.vigencia_hasta ? formatDate(t.vigencia_hasta) : 'vigente'}
                                                </span>
                                            </li>
                                        ))}
                                    </ul>
                                )}
                                {esTesorero && p.genera_cuota && <NuevaTarifa plan={p} />}
                                {esTesorero && (
                                    <div>
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            onClick={() =>
                                                router.put(
                                                    route('planes.update', p.id),
                                                    {
                                                        nombre: p.nombre,
                                                        descripcion: p.descripcion,
                                                        genera_cuota: p.genera_cuota,
                                                        es_adicional_familiar: p.es_adicional_familiar,
                                                        activo: !p.activo,
                                                    },
                                                    { preserveScroll: true },
                                                )
                                            }
                                        >
                                            {p.activo ? 'Desactivar' : 'Activar'}
                                        </Button>
                                    </div>
                                )}
                            </CardContent>
                        </Card>
                    ))}
                </div>
            </div>
        </AppLayout>
    );
}
