import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { NativeSelect } from '@/components/ui/native-select';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem, type Opcion, type SharedData } from '@/types';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { FormEventHandler } from 'react';

interface Categoria {
    id: number;
    nombre: string;
    tipo: string;
    tipo_label: string;
    activa: boolean;
    gastos_count: number;
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Categorías de gasto', href: '/configuracion/categorias-gasto' }];

export default function CategoriasGasto({ categorias, tipos }: { categorias: Categoria[]; tipos: Opcion[] }) {
    const { auth } = usePage<SharedData>().props;
    const esTesorero = auth.user.rol === 'tesorero';
    const form = useForm({ nombre: '', tipo: 'eventual' });
    const crear: FormEventHandler = (e) => {
        e.preventDefault();
        form.post(route('categorias-gasto.store'), { preserveScroll: true, onSuccess: () => form.reset('nombre') });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Categorías de gasto" />
            <div className="flex max-w-3xl flex-col gap-4 p-4">
                <h1 className="text-2xl font-semibold">Categorías de gasto</h1>
                {esTesorero && (
                    <form onSubmit={crear} className="flex flex-wrap items-start gap-3">
                        <div className="grid gap-1">
                            <Label htmlFor="cg-nombre">Nueva categoría</Label>
                            <Input id="cg-nombre" value={form.data.nombre} onChange={(e) => form.setData('nombre', e.target.value)} />
                            <InputError message={form.errors.nombre} />
                        </div>
                        <div className="grid gap-1">
                            <Label htmlFor="cg-tipo">Tipo</Label>
                            <NativeSelect id="cg-tipo" className="w-40" value={form.data.tipo} onChange={(e) => form.setData('tipo', e.target.value)}>
                                {tipos.map((t) => (
                                    <option key={t.value} value={t.value}>
                                        {t.label}
                                    </option>
                                ))}
                            </NativeSelect>
                        </div>
                        <Button type="submit" className="mt-5" disabled={form.processing || !form.data.nombre}>
                            Agregar
                        </Button>
                    </form>
                )}
                <div className="rounded-md border">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Nombre</TableHead>
                                <TableHead>Tipo</TableHead>
                                <TableHead>Activa</TableHead>
                                <TableHead className="text-right">Gastos</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {categorias.map((c) => (
                                <TableRow key={c.id}>
                                    <TableCell className="font-medium">{c.nombre}</TableCell>
                                    <TableCell>{c.tipo_label}</TableCell>
                                    <TableCell>
                                        <Checkbox
                                            checked={c.activa}
                                            disabled={!esTesorero}
                                            onCheckedChange={(v) =>
                                                router.put(
                                                    route('categorias-gasto.update', c.id),
                                                    { nombre: c.nombre, tipo: c.tipo, activa: v === true },
                                                    { preserveScroll: true },
                                                )
                                            }
                                            aria-label={`${c.nombre} activa`}
                                        />
                                    </TableCell>
                                    <TableCell className="text-right">{c.gastos_count}</TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                </div>
            </div>
        </AppLayout>
    );
}
