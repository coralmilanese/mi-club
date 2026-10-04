import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { FormEventHandler } from 'react';

interface Tipo {
    id: number;
    nombre: string;
    codigo: string;
    obligatorio: boolean;
    activo: boolean;
    documentos_count: number;
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Tipos de documento', href: '/configuracion/tipos-documento' }];

export default function TiposDocumento({ tipos }: { tipos: Tipo[] }) {
    const { auth, errors } = usePage<SharedData & { errors: Record<string, string> }>().props;
    const esTesorero = auth.user.rol === 'tesorero';
    const form = useForm<{ nombre: string; obligatorio: boolean }>({ nombre: '', obligatorio: false });

    const crear: FormEventHandler = (e) => {
        e.preventDefault();
        form.post(route('tipos-documento.store'), { preserveScroll: true, onSuccess: () => form.reset() });
    };

    const actualizar = (t: Tipo, cambios: Partial<Pick<Tipo, 'obligatorio' | 'activo'>>) =>
        router.put(
            route('tipos-documento.update', t.id),
            { nombre: t.nombre, obligatorio: t.obligatorio, activo: t.activo, ...cambios },
            { preserveScroll: true },
        );

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Tipos de documento" />
            <div className="flex max-w-3xl flex-col gap-4 p-4">
                <h1 className="text-2xl font-semibold">Tipos de documento</h1>
                <p className="text-muted-foreground text-sm">Los tipos obligatorios arman el checklist de documentación faltante de cada socio.</p>

                {esTesorero && (
                    <form onSubmit={crear} className="flex flex-wrap items-end gap-3">
                        <div className="grid gap-1">
                            <Label htmlFor="nombre-tipo">Nuevo tipo</Label>
                            <Input
                                id="nombre-tipo"
                                value={form.data.nombre}
                                onChange={(e) => form.setData('nombre', e.target.value)}
                                placeholder="Ej.: Certificado médico"
                            />
                            <InputError message={form.errors.nombre} />
                        </div>
                        <Label className="flex items-center gap-2 pb-2">
                            <Checkbox checked={form.data.obligatorio} onCheckedChange={(v) => form.setData('obligatorio', v === true)} />
                            Obligatorio
                        </Label>
                        <Button type="submit" disabled={form.processing || !form.data.nombre}>
                            Agregar
                        </Button>
                    </form>
                )}
                <InputError message={errors.tipo} />

                <div className="rounded-md border">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Nombre</TableHead>
                                <TableHead>Obligatorio</TableHead>
                                <TableHead>Activo</TableHead>
                                <TableHead>Cargados</TableHead>
                                <TableHead />
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {tipos.map((t) => (
                                <TableRow key={t.id}>
                                    <TableCell className="font-medium">{t.nombre}</TableCell>
                                    <TableCell>
                                        <Checkbox
                                            checked={t.obligatorio}
                                            disabled={!esTesorero}
                                            onCheckedChange={(v) => actualizar(t, { obligatorio: v === true })}
                                            aria-label={`${t.nombre} obligatorio`}
                                        />
                                    </TableCell>
                                    <TableCell>
                                        <Checkbox
                                            checked={t.activo}
                                            disabled={!esTesorero}
                                            onCheckedChange={(v) => actualizar(t, { activo: v === true })}
                                            aria-label={`${t.nombre} activo`}
                                        />
                                    </TableCell>
                                    <TableCell>{t.documentos_count}</TableCell>
                                    <TableCell className="text-right">
                                        {esTesorero && (
                                            <Button
                                                variant="ghost"
                                                size="sm"
                                                onClick={() =>
                                                    confirm(`¿Eliminar "${t.nombre}"?`) &&
                                                    router.delete(route('tipos-documento.destroy', t.id), { preserveScroll: true })
                                                }
                                            >
                                                Eliminar
                                            </Button>
                                        )}
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                </div>
            </div>
        </AppLayout>
    );
}
