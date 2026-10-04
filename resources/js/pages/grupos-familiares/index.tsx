import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { NativeSelect } from '@/components/ui/native-select';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { FormEventHandler } from 'react';

interface Persona {
    id: number;
    nombre: string;
}
interface Grupo {
    id: number;
    nombre: string;
    titular: Persona | null;
    adherentes: Persona[];
}
interface Props {
    grupos: Grupo[];
    disponibles: Persona[];
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Grupos familiares', href: '/grupos-familiares' }];

function NuevoGrupo({ disponibles }: { disponibles: Persona[] }) {
    const form = useForm({ titular_socio_id: '', nombre: '' });
    const enviar: FormEventHandler = (e) => {
        e.preventDefault();
        form.post(route('grupos-familiares.store'), { preserveScroll: true, onSuccess: () => form.reset() });
    };

    return (
        <form onSubmit={enviar} className="flex flex-wrap items-end gap-2">
            <div className="grid gap-1">
                <Label htmlFor="titular">Titular</Label>
                <NativeSelect
                    id="titular"
                    className="w-64"
                    value={form.data.titular_socio_id}
                    onChange={(e) => form.setData('titular_socio_id', e.target.value)}
                >
                    <option value="">Elegí un socio…</option>
                    {disponibles.map((s) => (
                        <option key={s.id} value={s.id}>
                            {s.nombre}
                        </option>
                    ))}
                </NativeSelect>
                <InputError message={form.errors.titular_socio_id} />
            </div>
            <div className="grid gap-1">
                <Label htmlFor="nombre-grupo">Nombre (opcional)</Label>
                <Input id="nombre-grupo" value={form.data.nombre} onChange={(e) => form.setData('nombre', e.target.value)} placeholder="Familia …" />
            </div>
            <Button type="submit" disabled={form.processing || !form.data.titular_socio_id}>
                Crear grupo
            </Button>
        </form>
    );
}

function AgregarAdherente({ grupo, disponibles }: { grupo: Grupo; disponibles: Persona[] }) {
    const form = useForm({ socio_id: '' });
    const enviar: FormEventHandler = (e) => {
        e.preventDefault();
        form.post(route('grupos-familiares.miembros.store', grupo.id), { preserveScroll: true, onSuccess: () => form.reset() });
    };

    return (
        <form onSubmit={enviar} className="flex items-start gap-2">
            <div className="grid gap-1">
                <NativeSelect
                    className="w-56"
                    value={form.data.socio_id}
                    onChange={(e) => form.setData('socio_id', e.target.value)}
                    aria-label="Agregar adherente"
                >
                    <option value="">Agregar adherente…</option>
                    {disponibles.map((s) => (
                        <option key={s.id} value={s.id}>
                            {s.nombre}
                        </option>
                    ))}
                </NativeSelect>
                <InputError message={form.errors.socio_id} />
            </div>
            <Button type="submit" size="sm" variant="secondary" disabled={form.processing || !form.data.socio_id}>
                Agregar
            </Button>
        </form>
    );
}

export default function GruposIndex({ grupos, disponibles }: Props) {
    const { auth } = usePage<SharedData>().props;
    const esTesorero = auth.user.rol === 'tesorero';

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Grupos familiares" />
            <div className="flex flex-col gap-4 p-4">
                <h1 className="text-2xl font-semibold">Grupos familiares</h1>
                <p className="text-muted-foreground max-w-2xl text-sm">
                    El titular paga su cuota completa más un adicional por cada adherente. Los adherentes quedan cubiertos por el grupo.
                </p>

                {esTesorero && <NuevoGrupo disponibles={disponibles} />}

                <div className="grid gap-4 md:grid-cols-2">
                    {grupos.map((g) => (
                        <Card key={g.id}>
                            <CardHeader>
                                <CardTitle className="flex items-center justify-between">
                                    {g.nombre}
                                    {esTesorero && (
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            onClick={() =>
                                                confirm(`¿Disolver el grupo "${g.nombre}"?`) &&
                                                router.delete(route('grupos-familiares.destroy', g.id), { preserveScroll: true })
                                            }
                                        >
                                            Disolver
                                        </Button>
                                    )}
                                </CardTitle>
                            </CardHeader>
                            <CardContent className="grid gap-3 text-sm">
                                <p>
                                    <span className="text-muted-foreground">Titular: </span>
                                    {g.titular && (
                                        <Link href={route('socios.show', g.titular.id)} className="font-medium hover:underline">
                                            {g.titular.nombre}
                                        </Link>
                                    )}
                                </p>
                                <ul className="grid gap-1">
                                    {g.adherentes.map((a) => (
                                        <li key={a.id} className="flex items-center justify-between">
                                            <Link href={route('socios.show', a.id)} className="hover:underline">
                                                {a.nombre}
                                            </Link>
                                            {esTesorero && (
                                                <Button
                                                    variant="ghost"
                                                    size="sm"
                                                    onClick={() =>
                                                        router.delete(route('grupos-familiares.miembros.destroy', [g.id, a.id]), {
                                                            preserveScroll: true,
                                                        })
                                                    }
                                                >
                                                    Quitar
                                                </Button>
                                            )}
                                        </li>
                                    ))}
                                    {g.adherentes.length === 0 && <li className="text-muted-foreground">Sin adherentes.</li>}
                                </ul>
                                {esTesorero && <AgregarAdherente grupo={g} disponibles={disponibles} />}
                            </CardContent>
                        </Card>
                    ))}
                    {grupos.length === 0 && <p className="text-muted-foreground text-sm">Todavía no hay grupos familiares.</p>}
                </div>
            </div>
        </AppLayout>
    );
}
