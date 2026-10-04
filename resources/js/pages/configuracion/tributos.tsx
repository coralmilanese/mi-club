import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { formatDate } from '@/lib/format';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { FormEventHandler } from 'react';

interface TributoItem {
    id: number;
    nombre: string;
    codigo: string;
    base: string;
    es_retencion: boolean;
    incluye_retenciones_en_base: boolean;
    concepto: string;
    activo: boolean;
    alicuotas: { id: number; alicuota: string; vigencia_desde: string; vigencia_hasta: string | null }[];
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Tributos', href: '/configuracion/tributos' }];

function NuevaAlicuota({ tributo }: { tributo: TributoItem }) {
    const form = useForm({ alicuota: '', vigencia_desde: '' });
    const enviar: FormEventHandler = (e) => {
        e.preventDefault();
        form.post(route('tributos.alicuotas.store', tributo.id), { preserveScroll: true, onSuccess: () => form.reset() });
    };

    return (
        <form onSubmit={enviar} className="flex flex-wrap items-start gap-2 border-t pt-3">
            <div className="grid gap-1">
                <Label className="text-xs" htmlFor={`al-${tributo.id}`}>
                    Nueva alícuota (%)
                </Label>
                <Input
                    id={`al-${tributo.id}`}
                    type="number"
                    step="0.00001"
                    min="0"
                    max="100"
                    className="w-32"
                    value={form.data.alicuota}
                    onChange={(e) => form.setData('alicuota', e.target.value)}
                />
                <InputError message={form.errors.alicuota} />
            </div>
            <div className="grid gap-1">
                <Label className="text-xs" htmlFor={`av-${tributo.id}`}>
                    Vigente desde
                </Label>
                <Input
                    id={`av-${tributo.id}`}
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
                disabled={form.processing || !form.data.alicuota || !form.data.vigencia_desde}
            >
                Registrar
            </Button>
        </form>
    );
}

export default function Tributos({ tributos }: { tributos: TributoItem[] }) {
    const { auth } = usePage<SharedData>().props;
    const esTesorero = auth.user.rol === 'tesorero';

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Tributos" />
            <div className="flex max-w-5xl flex-col gap-4 p-4">
                <h1 className="text-2xl font-semibold">Tributos y alícuotas</h1>
                <p className="text-muted-foreground max-w-3xl text-sm">
                    La alícuota se resuelve por la fecha de cada movimiento, nunca "la actual": al cargar una nueva, los días ya liquidados no se
                    modifican.
                </p>
                <div className="grid gap-4 md:grid-cols-3">
                    {tributos.map((t) => (
                        <Card key={t.id} className={t.activo ? '' : 'opacity-60'}>
                            <CardHeader>
                                <CardTitle className="flex flex-wrap items-center gap-2 text-base">
                                    {t.nombre}
                                    <Badge variant="outline">Base: {t.base}</Badge>
                                    {t.es_retencion && <Badge variant="secondary">Retención</Badge>}
                                    {t.incluye_retenciones_en_base && <Badge variant="secondary">Incluye retenciones</Badge>}
                                </CardTitle>
                            </CardHeader>
                            <CardContent className="grid gap-3 text-sm">
                                <p className="text-muted-foreground text-xs">Concepto en el libro: {t.concepto}</p>
                                <ul className="grid gap-1">
                                    {t.alicuotas.map((a) => (
                                        <li key={a.id} className="flex justify-between">
                                            <span className="font-medium">{Number(a.alicuota)}%</span>
                                            <span className="text-muted-foreground">
                                                {formatDate(a.vigencia_desde)} → {a.vigencia_hasta ? formatDate(a.vigencia_hasta) : 'vigente'}
                                            </span>
                                        </li>
                                    ))}
                                </ul>
                                {esTesorero && <NuevaAlicuota tributo={t} />}
                                {esTesorero && (
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        className="w-fit"
                                        onClick={() => router.put(route('tributos.update', t.id), { activo: !t.activo }, { preserveScroll: true })}
                                    >
                                        {t.activo ? 'Desactivar' : 'Activar'}
                                    </Button>
                                )}
                            </CardContent>
                        </Card>
                    ))}
                </div>
            </div>
        </AppLayout>
    );
}
