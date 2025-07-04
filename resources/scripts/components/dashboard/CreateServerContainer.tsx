import React, { useState, useEffect } from 'react';
import { useHistory } from 'react-router-dom';
import { Formik, Form, Field } from 'formik';
import * as Yup from 'yup';
import tw from 'twin.macro';

import PageContentBlock from '@/components/elements/PageContentBlock';
import Button from '@/components/elements/Button';
import Input from '@/components/elements/Input';
import Label from '@/components/elements/Label';
import InputError from '@/components/elements/InputError';
import TitledGreyBox from '@/components/elements/TitledGreyBox';
import Spinner from '@/components/elements/Spinner';
import useFlash from '@/plugins/useFlash';
import createServer from '@/api/createServer';
import getProducts, { Product } from '@/api/getProducts';

// Helper function to format credits as price
const formatPrice = (credits: number): string => {
    if (credits === 0) return 'Free';
    // Convert credits to dollar amount (assuming 100 credits = $1, adjust as needed)
    const dollars = credits / 100;
    return `$${dollars.toFixed(2)}/month`;
};

interface FormValues {
    name: string;
    description: string;
    productId: number | null;
}

const CreateServerContainer = () => {
    const history = useHistory();
    const { addError, addFlash } = useFlash();
    const [products, setProducts] = useState<Product[]>([]);
    const [isLoadingProducts, setIsLoadingProducts] = useState(true);
    const [selectedProduct, setSelectedProduct] = useState<Product | null>(null);

    // Fetch products on component mount
    useEffect(() => {
        const fetchProducts = async () => {
            try {
                const fetchedProducts = await getProducts();
                setProducts(fetchedProducts);
            } catch (error) {
                console.error('Failed to fetch products:', error);
                addError({ key: 'create-server', message: 'Failed to load server plans. Please refresh the page.' });
            } finally {
                setIsLoadingProducts(false);
            }
        };

        fetchProducts();
    }, [addError]);

    // Create validation schema with dynamic product IDs
    const validationSchema = Yup.object({
        name: Yup.string()
            .min(3, 'Server name must be at least 3 characters')
            .max(50, 'Server name cannot exceed 50 characters')
            .matches(/^[a-zA-Z0-9_\-. ]+$/, 'Server name can only contain letters, numbers, spaces, dots, hyphens, and underscores')
            .required('Server name is required'),
        description: Yup.string()
            .max(200, 'Description cannot exceed 200 characters'),
        productId: Yup.number()
            .nullable()
            .oneOf(products.map(p => p.id), 'Please select a valid plan')
            .required('Please select a server plan'),
    });

    const onSubmit = async (values: FormValues, { setSubmitting }: { setSubmitting: (isSubmitting: boolean) => void }) => {
        const product = products.find(p => p.id === values.productId);
        if (!product) {
            addError({ key: 'create-server', message: 'Invalid product selected' });
            setSubmitting(false);
            return;
        }

        try {
            const serverData = {
                name: values.name,
                description: values.description,
                memory: product.memory,
                cpu: product.cpu,
                disk: product.disk,
                productId: product.id,
            };

            await createServer(serverData);
            addFlash({ type: 'success', key: 'create-server', message: 'Server is being created! This may take a few minutes.' });
            history.push('/');
        } catch (error: any) {
            console.error('Failed to create server:', error);
            addError({ key: 'create-server', message: error.message || 'Failed to create server. Please try again.' });
        } finally {
            setSubmitting(false);
        }
    };

    return (
        <PageContentBlock title={'Create Server'} showFlashKey={'create-server'}>
            <div css={tw`w-full`}>
                <div css={tw`flex items-center justify-between mb-6`}>
                    <div>
                        <h1 css={tw`text-2xl text-neutral-50 mb-1`}>Create New Server</h1>
                        <p css={tw`text-sm text-neutral-400`}>Choose a plan and configure your new server</p>
                    </div>
                    <Button
                        isSecondary
                        onClick={() => history.push('/')}
                        css={tw`w-auto`}
                    >
                        Back to Dashboard
                    </Button>
                </div>

                {isLoadingProducts ? (
                    <div css={tw`flex justify-center items-center py-12`}>
                        <Spinner size={'large'} />
                        <span css={tw`ml-3 text-neutral-400`}>Loading server plans...</span>
                    </div>
                ) : (
                    <Formik
                        initialValues={{
                            name: '',
                            description: '',
                            productId: null,
                        }}
                        validationSchema={validationSchema}
                        onSubmit={onSubmit}
                    >
                        {({ values, errors, touched, setFieldValue, isSubmitting }) => (
                            <Form>
                                <div css={tw`grid grid-cols-1 lg:grid-cols-3 gap-8`}>
                                    {/* Server Plans */}
                                    <div css={tw`lg:col-span-2`}>
                                        <TitledGreyBox title={'Select a Plan'} css={tw`mb-6`}>
                                            {products.length === 0 ? (
                                                <div css={tw`text-center py-8`}>
                                                    <p css={tw`text-neutral-400`}>No server plans are currently available.</p>
                                                </div>
                                            ) : (
                                                <div css={tw`grid grid-cols-1 md:grid-cols-2 gap-4`}>
                                                    {products.map((product) => (
                                                        <div
                                                            key={product.id}
                                                            css={[
                                                                tw`relative p-4 border rounded-lg cursor-pointer transition-all duration-200`,
                                                                values.productId === product.id
                                                                    ? tw`border-primary-400 bg-primary-400 bg-opacity-10`
                                                                    : tw`border-neutral-500 hover:border-neutral-400`,
                                                            ]}
                                                            onClick={() => {
                                                                setFieldValue('productId', product.id);
                                                                setSelectedProduct(product);
                                                            }}
                                                        >
                                                            <div css={tw`flex justify-between items-start mb-2`}>
                                                                <h3 css={tw`text-lg font-semibold text-neutral-50`}>{product.name}</h3>
                                                                <span css={tw`text-lg font-bold text-primary-400`}>{formatPrice(product.credits)}</span>
                                                            </div>
                                                            
                                                            <p css={tw`text-sm text-neutral-300 mb-4`}>{product.description || 'No description available'}</p>
                                                            
                                                            <div css={tw`space-y-2 text-sm`}>
                                                                <div css={tw`flex justify-between`}>
                                                                    <span css={tw`text-neutral-400`}>Memory:</span>
                                                                    <span css={tw`text-neutral-200`}>{product.memory / 1000} GB</span>
                                                                </div>
                                                                <div css={tw`flex justify-between`}>
                                                                    <span css={tw`text-neutral-400`}>CPU:</span>
                                                                    <span css={tw`text-neutral-200`}>{product.cpu / 100} vCores</span>
                                                                </div>
                                                                <div css={tw`flex justify-between`}>
                                                                    <span css={tw`text-neutral-400`}>Storage:</span>
                                                                    <span css={tw`text-neutral-200`}>{product.disk / 1000} GB</span>
                                                                </div>
                                                                <div css={tw`flex justify-between`}>
                                                                    <span css={tw`text-neutral-400`}>Credits:</span>
                                                                    <span css={tw`text-neutral-200`}>{product.credits}</span>
                                                                </div>
                                                            </div>
                                                            
                                                            {values.productId === product.id && (
                                                                <div css={tw`absolute top-2 right-2 w-6 h-6 bg-primary-400 rounded-full flex items-center justify-center`}>
                                                                    <svg css={tw`w-4 h-4 text-white`} fill="currentColor" viewBox="0 0 20 20">
                                                                        <path fillRule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clipRule="evenodd" />
                                                                    </svg>
                                                                </div>
                                                            )}
                                                        </div>
                                                    ))}
                                                </div>
                                            )}
                                            <InputError errors={errors} touched={touched} name="productId" />
                                        </TitledGreyBox>
                                    </div>

                                {/* Server Configuration */}
                                <div>
                                    <TitledGreyBox title={'Server Configuration'} css={tw`mb-6`}>
                                        <div css={tw`space-y-4`}>
                                            <div>
                                                <Label htmlFor={'name'}>Server Name</Label>
                                                <Field name={'name'}>
                                                    {({ field }: { field: any }) => (
                                                        <Input
                                                            {...field}
                                                            id={'name'}
                                                            type={'text'}
                                                            placeholder={'My Awesome Server'}
                                                        />
                                                    )}
                                                </Field>
                                                <InputError errors={errors} touched={touched} name="name" />
                                            </div>

                                            <div>
                                                <Label htmlFor={'description'}>Description (Optional)</Label>
                                                <Field name={'description'}>
                                                    {({ field }: { field: any }) => (
                                                        <Input
                                                            {...field}
                                                            id={'description'}
                                                            type={'text'}
                                                            placeholder={'Server description...'}
                                                        />
                                                    )}
                                                </Field>
                                                <InputError errors={errors} touched={touched} name="description" />
                                            </div>

                                            {selectedProduct && (
                                                <div css={tw`mt-6 p-4 bg-neutral-800 rounded-lg`}>
                                                    <h4 css={tw`text-sm font-semibold text-neutral-300 mb-2`}>Selected Plan Summary</h4>
                                                    <div css={tw`space-y-1 text-xs text-neutral-400`}>
                                                        <div>{selectedProduct.name} - {formatPrice(selectedProduct.credits)}</div>
                                                        <div>{selectedProduct.memory / 1024}GB RAM, {selectedProduct.cpu / 100} vCores, {selectedProduct.disk / 1000}GB Storage</div>
                                                        {/* {selectedProduct.eggs.length > 0 && (
                                                            <div>Eggs: {selectedProduct.eggs.map(e => e.name).join(', ')}</div>
                                                        )} */}
                                                        <div>{selectedProduct.credits} credits required</div>
                                                    </div>
                                                </div>
                                            )}
                                        </div>
                                    </TitledGreyBox>

                                    <Button
                                        type={'submit'}
                                        disabled={!values.productId || !values.name || isSubmitting}
                                        isLoading={isSubmitting}
                                        color={'green'}
                                        size={'xlarge'}
                                        css={tw`w-full`}
                                    >
                                        Create Server
                                    </Button>
                                </div>
                            </div>
                        </Form>
                    )}
                </Formik>
                )}
            </div>
        </PageContentBlock>
    );
};

export default CreateServerContainer; 