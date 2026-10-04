import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { NativeSelect } from '@/components/ui/native-select';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, useForm } from '@inertiajs/react';
import { FormEventHandler, useMemo, useState } from 'react';

interface Props {
    socios: { id: number; nombre: string; categoria: string; sede: string; plan_actual: string | null; plan_actual_id: number | null }[];
    planes: { id: number; nombre: string }[];
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Planes y tarifas', href: '/planes' },
    { title: 'Asignación masiva', href: '#' },
];

export default function AsignacionMasiva({ socios, planes }: Props) {
    const form = useForm<{ socio_ids: number[]; plan_id: string; desde: string; motivo: string }>({
        socio_ids: [],
        plan_id: '',
        desde: new Date().toISOString().slice(0, 8) + '01',
        motivo: '',
    });
    const [soloSinPlan, setSoloSinPlan] = useState(false);
    const visibles = useMemo(() => socios.filter((s) => !soloSinPlan || s.plan_actual_id === null), [socios, soloSinPlan]);
    const errores = form.errors as Record<string, string>;

    const alternar = (id: number, marcado: boolean) =>
        form.setData('socio_ids', marcado ? [...form.data.socio_ids, id] : form.data.socio_ids.filter((x) => x !== id));
    const enviar: FormEventHandler = (e) => {
        e.preventDefault();
        form.post(route('planes.masiva.store'));
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Asignación masiva de planes" />
            <form onSubmit={enviar} className="flex max-w-4xl flex-col gap-4 p-4">
                <h1 className="text-2xl font-semibold">Asignación masiva de planes</h1>

                <div className="flex flex-wrap items-end gap-3">
                    <div className="grid gap-1">
                        <Label htmlFor="plan">Plan</Label>
                        <NativeSelect id="plan" className="w-64" value={form.data.plan_id} onChange={(e) => form.setData('plan_id', e.target.value)}>
                            <option value="">Elegí un plan…</option>
                            {planes.map((p) => (
                                <option key={p.id} value={p.id}>
                                    {p.nombre}
                                </option>
                            ))}
                        </NativeSelect>
                        <InputError message={errores.plan_id} />
                    </div>
                    <div className="grid gap-1">
                        <Label htmlFor="desde">Desde</Label>
                        <Input
                            id="desde"
                            type="date"
                            className="w-44"
                            value={form.data.desde}
                            onChange={(e) => form.setData('desde', e.target.value)}
                        />
                        <InputError message={errores.desde} />
                    </div>
                    <div className="grid gap-1">
                        <Label htmlFor="motivo">Motivo (opcional)</Label>
                        <Input id="motivo" className="w-64" value={form.data.motivo} onChange={(e) => form.setData('motivo', e.target.value)} />
                    </div>
                </div>

                <div className="flex items-center gap-4 text-sm">
                    <Label className="flex items-center gap-2">
                        <Checkbox checked={soloSinPlan} onCheckedChange={(v) => setSoloSinPlan(v === true)} />
                        Mostrar solo socios sin plan
                    </Label>
                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        onClick={() =>
                            form.setData(
                                'socio_ids',
                                visibles.map((s) => s.id),
                            )
                        }
                    >
                        Marcar visibles
                    </Button>
                    <Button type="button" variant="ghost" size="sm" onClick={() => form.setData('socio_ids', [])}>
                        Limpiar
                    </Button>
                    <span className="text-muted-foreground">{form.data.socio_ids.length} seleccionados</span>
                </div>
                <InputError message={errores.socio_ids} />

                <ul className="divide-y rounded-md border text-sm">
                    {visibles.map((s) => (
                        <li key={s.id}>
                            <Label className="flex cursor-pointer items-center gap-3 px-3 py-2">
                                <Checkbox checked={form.data.socio_ids.includes(s.id)} onCheckedChange={(v) => alternar(s.id, v === true)} />
                                <span className="flex-1 font-medium">{s.nombre}</span>
                                <span className="text-muted-foreground">
                                    {s.categoria} · {s.sede}
                                </span>
                                <span className={s.plan_actual ? '' : 'text-amber-600'}>{s.plan_actual ?? 'Sin plan'}</span>
                            </Label>
                        </li>
                    ))}
                </ul>

                <div>
                    <Button type="submit" disabled={form.processing || form.data.socio_ids.length === 0 || !form.data.plan_id}>
                        Asignar plan a {form.data.socio_ids.length} socios
                    </Button>
                </div>
            </form>
        </AppLayout>
    );
}
